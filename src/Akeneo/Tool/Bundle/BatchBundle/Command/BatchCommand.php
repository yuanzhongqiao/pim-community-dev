<?php

declare(strict_types=1);

namespace Akeneo\Tool\Bundle\BatchBundle\Command;

use Akeneo\Tool\Bundle\BatchBundle\Monolog\Handler\BatchLogHandler;
use Akeneo\Tool\Bundle\BatchBundle\Notification\Notifier;
use Akeneo\Tool\Component\Batch\Item\ExecutionContext;
use Akeneo\Tool\Component\Batch\Job\BatchStatus;
use Akeneo\Tool\Component\Batch\Job\ExitStatus;
use Akeneo\Tool\Component\Batch\Job\JobInterface;
use Akeneo\Tool\Component\Batch\Job\JobParameters;
use Akeneo\Tool\Component\Batch\Job\JobParametersFactory;
use Akeneo\Tool\Component\Batch\Job\JobParametersValidator;
use Akeneo\Tool\Component\Batch\Job\JobRegistry;
use Akeneo\Tool\Component\Batch\Job\JobRepositoryInterface;
use Akeneo\Tool\Component\Batch\Model\JobExecution;
use Akeneo\Tool\Component\Batch\Model\JobInstance;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Batch command
 *
 * @author    Benoit Jacquemont <benoit@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class BatchCommand extends Command
{
    protected static $defaultName = 'akeneo:batch:job';
    protected static $defaultDescription = '[Internal] Please use "akeneo:batch:publish-job-to-queue" to launch a registered job instance';

    const EXIT_SUCCESS_CODE = 0;
    const EXIT_ERROR_CODE = 1;
    const EXIT_WARNING_CODE = 2;

    public function __construct(
        private LoggerInterface $logger,
        private BatchLogHandler $batchLogHandler,
        private JobRepositoryInterface $jobRepository,
        private ManagerRegistry $doctrine,
        private ValidatorInterface $validator,
        private Notifier $notifier,
        private JobRegistry $jobRegistry,
        private JobParametersFactory $jobParametersFactory,
        private JobParametersValidator $jobParametersValidator,
        private string $jobInstanceClass,
        private string $jobExecutionClass
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Job instance code')
            ->addArgument('execution', InputArgument::OPTIONAL, 'Job execution id')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Override job configuration (formatted as json. ie: ' .
                'php bin/console akeneo:batch:job -c "{\"filePath\":\"/tmp/foo.csv\"}" acme_product_import)'
            )
            ->addOption(
                'username',
                null,
                InputOption::VALUE_REQUIRED,
                'Username to launch the job instance with'
            )
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'The email to notify at the end of the job execution'
            )
            ->addOption(
                'no-log',
                null,
                InputOption::VALUE_NONE,
                'Don\'t display logs'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $noLog = $input->getOption('no-log');

        if (!$noLog and $this->logger instanceof Logger) {
            $this->logger->pushHandler(new ConsoleHandler($output));
        }

        // Override mail notifier recipient email
        if ($email = $input->getOption('email')) {
            $errors = $this->validator->validate($email, new Assert\Email());
            if (count($errors) > 0) {
                throw new \RuntimeException(
                    sprintf('Email "%s" is invalid: %s', $email, $this->getErrorMessages($errors))
                );
            }
            $this->notifier->setRecipientEmail($email);
        }

        $executionId = $input->hasArgument('execution') ? $input->getArgument('execution') : null;

        if (null !== $executionId && null !== $input->getOption('config')) {
            throw new \InvalidArgumentException('Configuration option cannot be specified when launching a job execution.');
        }

        if (null !== $executionId && $input->hasOption('username') && null !== $input->getOption('username')) {
            throw new \InvalidArgumentException('Username option cannot be specified when launching a job execution.');
        }

        if (null !== $executionId) {
            /** @var JobExecution $jobExecution */
            $jobExecution = $this->getJobManager()->getRepository($this->jobExecutionClass)->find($executionId);
            if (!$jobExecution) {
                throw new \InvalidArgumentException(sprintf('Could not find job execution "%s".', $executionId));
            }
            if (!$jobExecution->getStatus()->isStarting() && !$jobExecution->getStatus()->isStopping()) {
                throw new \RuntimeException(
                    sprintf('Job execution "%s" has invalid status: %s', $executionId, $jobExecution->getStatus())
                );
            }
            if (null === $jobExecution->getExecutionContext()) {
                $jobExecution->setExecutionContext(new ExecutionContext());
            }
            $jobInstance = $jobExecution->getJobInstance();
            $job = $this->jobRegistry->get($jobInstance->getJobName());
        } else {
            $code = $input->getArgument('code');
            $jobInstance = $this->getJobManager()->getRepository($this->jobInstanceClass)->findOneBy(['code' => $code]);
            if (null === $jobInstance) {
                throw new \InvalidArgumentException(sprintf('Could not find job instance "%s".', $code));
            }

            $job = $this->jobRegistry->get($jobInstance->getJobName());
            $jobParameters = $this->createJobParameters($job, $jobInstance, $input);
            $this->validateJobParameters($job, $jobInstance, $jobParameters, $code);
            $jobExecution = $job->getJobRepository()->createJobExecution($job, $jobInstance, $jobParameters);

            $username = $input->getOption('username');
            if (null !== $username) {
                $jobExecution->setUser($username);
                $job->getJobRepository()->updateJobExecution($jobExecution);
            }
        }

        $jobExecution->setPid(getmypid());
        $job->getJobRepository()->updateJobExecution($jobExecution);

        $this->batchLogHandler->setSubDirectory($jobExecution->getId());

        $job->execute($jobExecution);

        $job->getJobRepository()->updateJobExecution($jobExecution);

        $verbose = $input->getOption('verbose');
        $exitCode = null;
        if (
            ExitStatus::COMPLETED === $jobExecution->getExitStatus()->getExitCode() ||
            (
                ExitStatus::STOPPED === $jobExecution->getExitStatus()->getExitCode() &&
                BatchStatus::STOPPED === $jobExecution->getStatus()->getValue()
            )
        ) {
            $nbWarnings = 0;
            /** @var StepExecution $stepExecution */
            foreach ($jobExecution->getStepExecutions() as $stepExecution) {
                $nbWarnings += count($stepExecution->getWarnings());
                if ($verbose) {
                    foreach ($stepExecution->getWarnings() as $warning) {
                        $output->writeln(sprintf('<comment>%s</comment>', $warning->getReason()));
                    }
                }
            }

            if (0 === $nbWarnings) {
                $output->writeln(
                    sprintf(
                        '<info>%s %s has been successfully executed.</info>',
                        ucfirst($jobInstance->getType()),
                        $jobInstance->getCode()
                    )
                );

                $exitCode = self::EXIT_SUCCESS_CODE;
            } else {
                $output->writeln(
                    sprintf(
                        '<comment>%s %s has been executed with %d warnings.</comment>',
                        ucfirst($jobInstance->getType()),
                        $jobInstance->getCode(),
                        $nbWarnings
                    )
                );

                $exitCode = self::EXIT_WARNING_CODE;
            }
        } else {
            $output->writeln(
                sprintf(
                    '<error>An error occurred during the %s execution.</error>',
                    $jobInstance->getType()
                )
            );
            $this->writeExceptions($output, $jobExecution->getFailureExceptions(), $verbose);
            foreach ($jobExecution->getStepExecutions() as $stepExecution) {
                $this->writeExceptions($output, $stepExecution->getFailureExceptions(), $verbose);
            }

            $exitCode = self::EXIT_ERROR_CODE;
        }

        return $exitCode;
    }

    /**
     * Writes failure exceptions to the output
     *
     * @param OutputInterface $output
     * @param array[]         $exceptions
     * @param boolean         $verbose
     */
    protected function writeExceptions(OutputInterface $output, array $exceptions, ?bool $verbose)
    {
        foreach ($exceptions as $exception) {
            $output->write(
                sprintf(
                    '<error>Error #%s in class %s: %s</error>',
                    $exception['code'],
                    $exception['class'],
                    strtr($exception['message'], $exception['messageParameters'])
                ),
                true
            );
            if ($verbose) {
                $output->write(sprintf('<error>%s</error>', $exception['trace']), true);
            }
        }
    }

    protected function getJobManager(): EntityManagerInterface
    {
        return $this->jobRepository->getJobManager();
    }

    protected function getDefaultEntityManager(): ObjectManager
    {
        return $this->doctrine->getManager();
    }

    protected function createJobParameters(JobInterface $job, JobInstance $jobInstance, InputInterface $input): JobParameters
    {
        $rawParameters = $jobInstance->getRawParameters();

        $config = $input->getOption('config') ? $this->decodeConfiguration($input->getOption('config')) : [];

        $rawParameters = array_merge($rawParameters, $config);

        return $this->jobParametersFactory->create($job, $rawParameters);
    }

    /**
     * @throws \RuntimeException
     */
    protected function validateJobParameters(JobInterface $job, JobInstance $jobInstance, JobParameters $jobParameters, string $code) : void
    {
        // We merge the JobInstance from the JobManager EntityManager to the DefaultEntityManager
        // in order to be able to have a working UniqueEntity validation
        $this->getDefaultEntityManager()->merge($jobInstance);
        $errors = $this->jobParametersValidator->validate($job, $jobParameters, ['Default', 'Execution']);

        if (count($errors) > 0) {
            throw new \RuntimeException(
                sprintf(
                    'Job instance "%s" running the job "%s" with parameters "%s" is invalid because of "%s"',
                    $code,
                    $job->getName(),
                    print_r($jobParameters->all(), true),
                    $this->getErrorMessages($errors)
                )
            );
        }
    }

    private function getErrorMessages(ConstraintViolationList $errors): string
    {
        $errorsStr = '';

        foreach ($errors as $error) {
            $errorsStr .= sprintf("\n  - %s", $error);
        }

        return $errorsStr;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function decodeConfiguration(string $data): array
    {
        return \json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}
