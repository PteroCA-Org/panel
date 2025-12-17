<?php

namespace App\Core\Trait;

use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use Exception;

trait ProductCrudControllerTrait
{
    private array $flashMessages = [];

    private function getNodesChoices(): array
    {
        try {
            $nodes = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nodes()
                ->getAllNodes()
                ->toArray();
            $locations = [];
            $choices = [];

            foreach ($nodes as $node) {
                if (empty($locations[$node['location_id']])) {
                    $locations[$node['location_id']] = $this->pterodactylApplicationService
                        ->getApplicationApi()
                        ->locations()
                        ->get($node['location_id']);
                }
                $choices[$locations[$node['location_id']]['short']][$node['name']] = $node['id'];
            }

            return $choices;
        } catch (Exception $exception) {
            $this->flashMessages[] = $exception->getMessage();
            return [];
        }
    }

    private function getNestsChoices(): array
    {
        try {
            $nests = $this->pterodactylApplicationService
                ->getApplicationApi()
                ->nests()
                ->all()
                ->toArray();
            $choices = [];

            foreach ($nests as $nest) {
                $choices[$nest['name']] = $nest['id'];
            }

            return $choices;
        } catch (Exception $exception) {
            $this->flashMessages[] = $exception->getMessage();
            return [];
        }
    }

    private function getEggsChoices(array $nests): array
    {
        try {
            $choices = [];
            foreach ($nests as $nestId) {
                $eggs = $this->nestEggsCacheService->getEggsForNest($nestId);

                foreach ($eggs as $egg) {
                    $choices[$egg['name']] = $egg['id'];
                }
            }

            return $choices;
        } catch (Exception $exception) {
            $this->flashMessages[] = $exception->getMessage();
            return [];
        }
    }

    private function getEggsConfigurationFromRequest(): array
    {
        $requestData = $this->requestStack->getCurrentRequest()->request->all();
        return $requestData['eggs_configuration'] ?? [];
    }

    private function getProductHelpPanel(): FormField
    {
        $helpText = sprintf(
            '<small class="text-muted"><a href="%s" target="_blank">%s</a></small>',
            'https://docs.pteroca.com/guidebook/product-configuration',
            $this->translator->trans('pteroca.crud.product.see_product_configuration_guide'),
        );

        return FormField::addPanel('')
                ->setHelp($helpText)
                ->hideOnIndex();
    }
}
