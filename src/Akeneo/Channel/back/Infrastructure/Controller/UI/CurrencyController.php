<?php

namespace Akeneo\Channel\Infrastructure\Controller\UI;

use Akeneo\Channel\Infrastructure\Component\Exception\LinkedChannelException;
use Akeneo\Channel\Infrastructure\Component\Model\Currency;
use Akeneo\Platform\Bundle\FrameworkBundle\Security\SecurityFacadeInterface;
use Akeneo\Tool\Component\StorageUtils\Saver\SaverInterface;
use Oro\Bundle\SecurityBundle\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Currency controller for configuration
 *
 * @author    Romain Monceau <romain@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CurrencyController
{
    public function __construct(
        private SaverInterface $currencySaver,
        private SecurityFacadeInterface $securityFacade,
    ) {
    }

    /**
     * Activate/Deactivate a currency
     */
    public function toggleAction(Currency $currency): JsonResponse
    {
        if (!$this->securityFacade->isGranted('pim_enrich_currency_toggle')) {
            throw AccessDeniedException::create(
                __CLASS__,
                __METHOD__,
            );
        }

        try {
            $currency->toggleActivation();
            $this->currencySaver->save($currency);
        } catch (LinkedChannelException $e) {
            return new JsonResponse([
                'successful' => false,
                'message' => 'flash.currency.error.linked_to_channel'
            ]);
        }

        return new JsonResponse([
            'successful' => true,
            'message' => 'flash.currency.updated'
        ]);
    }
}
