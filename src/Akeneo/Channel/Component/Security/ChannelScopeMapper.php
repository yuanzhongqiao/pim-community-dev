<?php

declare(strict_types=1);

namespace Akeneo\Channel\Component\Security;

use Akeneo\Tool\Component\Api\Security\ScopeMapperInterface;

/**
 * @copyright 2021 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ChannelScopeMapper implements ScopeMapperInterface
{
    private const SCOPE_READ_CHANNEL_LOCALIZATION = 'read_channel_localization';
    private const SCOPE_READ_CHANNEL_SETTINGS = 'read_channel_settings';
    private const SCOPE_WRITE_CHANNEL_SETTINGS = 'write_channel_settings';

    private const SCOPE_ACL_MAP = [
        self::SCOPE_READ_CHANNEL_LOCALIZATION => [
            'pim_api_locale_list',
            'pim_api_currency_list',
        ],
        self::SCOPE_READ_CHANNEL_SETTINGS => [
            'pim_api_channel_list',
        ],
        self::SCOPE_WRITE_CHANNEL_SETTINGS => [
            'pim_api_channel_edit',
        ],
    ];

    private const SCOPE_MESSAGE_MAP = [
        self::SCOPE_READ_CHANNEL_LOCALIZATION => [
            'icon' => 'channel_localization',
            'type' => 'view',
            'entities' => 'channel_localization',
        ],
        self::SCOPE_READ_CHANNEL_SETTINGS => [
            'icon' => 'channel_settings',
            'type' => 'view',
            'entities' => 'channel_settings',
        ],
        self::SCOPE_WRITE_CHANNEL_SETTINGS => [
            'icon' => 'channel_settings',
            'type' => 'edit',
            'entities' => 'channel_settings',
        ],
    ];

    private const SCOPE_HIERARCHY = [
        self::SCOPE_WRITE_CHANNEL_SETTINGS => [
            self::SCOPE_READ_CHANNEL_SETTINGS,
        ],
    ];

    public function getAllScopes(): array
    {
        return [
            self::SCOPE_READ_CHANNEL_LOCALIZATION,
            self::SCOPE_READ_CHANNEL_SETTINGS,
            self::SCOPE_WRITE_CHANNEL_SETTINGS,
        ];
    }

    public function getAcls(string $scopeName): array
    {
        if (!\array_key_exists($scopeName, self::SCOPE_ACL_MAP)) {
            return [];
        }

        return self::SCOPE_ACL_MAP[$scopeName];
    }

    public function getMessage(string $scope): array
    {
        if (!\array_key_exists($scope, self::SCOPE_MESSAGE_MAP)) {
            return [];
        }

        return self::SCOPE_MESSAGE_MAP[$scope];
    }

    public function getLowerHierarchyScopes(string $scope): array
    {
        if (!\array_key_exists($scope, self::SCOPE_HIERARCHY)) {
            return [];
        }

        return self::SCOPE_HIERARCHY[$scope];
    }
}
