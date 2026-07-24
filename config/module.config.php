<?php
$config = [
    'vufind' => [
        'plugin_managers' => [
            'command' => [
                'factories' => [
                    'CleanUpUserData\Command\Util\CleanUpUserDataCommand' => 'CleanUpUserData\Command\Util\CleanUpUserDataCommandFactory',
                ],
                'aliases' => [
                    'util/cleanup_user_data' => 'CleanUpUserData\Command\Util\CleanUpUserDataCommand',
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            'CleanUpUserData\Session\SessionInvalidator' => 'CleanUpUserData\Session\SessionInvalidatorFactory',
        ],
    ],
];

return $config;