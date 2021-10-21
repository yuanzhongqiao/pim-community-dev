<?php

declare(strict_types=1);

namespace Akeneo\Connectivity\Connection\Application\Apps\Service;

class RedeemCodeForToken implements RedeemCodeForTokenInterface
{
    public function redeem(string $code): string
    {
        return 'a_random_token';
    }
}
