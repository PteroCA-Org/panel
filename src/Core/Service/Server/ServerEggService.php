<?php

namespace App\Core\Service\Server;

use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class ServerEggService
{
    public function __construct(
        private PterodactylApplicationService $pterodactylApplicationService,
        private TranslatorInterface           $translator,
    )
    {
    }

    public function prepareEggsConfiguration(int $pterodactylServerId): string
    {
        $pterodactylServer = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->servers()
            ->getServer($pterodactylServerId, ['include' => 'variables']);

        $pterodactylServerVariables = $pterodactylServer->get('relationships')['variables'];
        $preparedVariables = [];
        foreach ($pterodactylServerVariables as $variable) {
            $preparedVariables[$variable->get('id')] = [
                'value' => $variable->get('default_value'),
                'user_viewable' => $variable->get('user_viewable'),
                'user_editable' => $variable->get('user_editable'),
            ];
        }

        $serverEggsConfiguration = [
            $pterodactylServer->get('egg') => [
                'options' => [
                    'startup' => [
                        'value' => $pterodactylServer->get('container')['startup_command'],
                    ],
                    'docker_image' => [
                        'value' => $pterodactylServer->get('container')['image'],
                    ],
                ],
                'variables' => $preparedVariables,
            ]
        ];

        return json_encode($serverEggsConfiguration);
    }

    public function prepareEggsDataByNest(int $nestId): array
    {
        $eggs = $this->pterodactylApplicationService
            ->getApplicationApi()
            ->nestEggs()
            ->all($nestId, ['include' => 'variables']);


        $translations = $this->getEggsTranslations();
        $choices = [];
        $loadedEggs = [];

        foreach ($eggs as $egg) {
            $eggId = $egg->get('id');
            $eggName = $egg->get('name');

            $choices[$eggName] = $eggId;
            $loadedEggs[$eggId] = $egg->toArray();
        }

        return [
            'choices' => $choices,
            'eggs' => $loadedEggs,
            'translations' => $translations,
        ];
    }

    private function getEggsTranslations(): array
    {
        return [
            'egg_information' => $this->translator->trans('pteroca.crud.product.egg_information'),
            'alert' => $this->translator->trans('pteroca.crud.product.egg_options_you_can_edit'),
            'variables' => $this->translator->trans('pteroca.crud.product.egg_variables'),
            'configuration' => $this->translator->trans('pteroca.crud.product.egg_configuration'),
            'default_configuration' => $this->translator->trans('pteroca.crud.product.egg_default_configuration'),
            'egg_variable_name' => $this->translator->trans('pteroca.crud.product.egg_variable_name'),
            'egg_variable_description' => $this->translator->trans('pteroca.crud.product.egg_variable_description'),
            'egg_variable_value' => $this->translator->trans('pteroca.crud.product.egg_variable_value'),
            'egg_variable_user_viewable' => $this->translator->trans('pteroca.crud.product.egg_variable_user_viewable'),
            'egg_variable_user_editable' => $this->translator->trans('pteroca.crud.product.egg_variable_user_editable'),
            'egg_variable_slot_variable' => $this->translator->trans('pteroca.crud.product.egg_variable_slot_variable'),
            'egg_variable_slot_variable_hint' => $this->translator->trans('pteroca.crud.product.egg_variable_slot_variable_hint'),
            'slot_variable_not_configured_egg' => $this->translator->trans('pteroca.crud.product.slot_variable_not_configured_egg'),
            'slot_variables_unconfigured_eggs' => $this->translator->trans('pteroca.crud.product.slot_variables_unconfigured_eggs'),
        ];
    }
}
