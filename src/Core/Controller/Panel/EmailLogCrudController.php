<?php

namespace App\Core\Controller\Panel;

use App\Core\Entity\EmailLog;
use App\Core\Enum\EmailTypeEnum;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Service\Crud\PanelCrudService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Symfony\Contracts\Translation\TranslatorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class EmailLogCrudController extends AbstractPanelController
{
    public function __construct(
        PanelCrudService $panelCrudService,
        RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($panelCrudService, $requestStack);
    }

    public static function getEntityFqcn(): string
    {
        return EmailLog::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $this->fields = [
            IdField::new('id')->hideOnForm(),
            ChoiceField::new('emailType', $this->translator->trans('pteroca.crud.email_log.email_type'))
                ->setChoices(array_combine(
                    array_map(fn($case) => $this->translator->trans('pteroca.email_types.' . $case->value), EmailTypeEnum::cases()),
                    EmailTypeEnum::cases()
                ))
                ->setDisabled()
                ->renderAsBadges(),
            AssociationField::new('user', $this->translator->trans('pteroca.crud.email_log.user'))
                ->setDisabled(),
            AssociationField::new('server', $this->translator->trans('pteroca.crud.email_log.server'))
                ->setDisabled(),
            CodeEditorField::new('metadata', $this->translator->trans('pteroca.crud.email_log.metadata'))
                ->setDisabled()
                ->formatValue(fn ($value) => empty($value) ? '' : json_encode($value, JSON_PRETTY_PRINT)),
            DateTimeField::new('sentAt', $this->translator->trans('pteroca.crud.email_log.sent_at'))
                ->setDisabled(),
        ];

        return parent::configureFields($pageName);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->disable(Action::EDIT, Action::DELETE, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::EMAIL_LOG->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.email_log.email_log'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.email_log.email_logs'))
            ->setDefaultSort(['sentAt' => 'DESC'])
            ->showEntityActionsInlined();

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('emailType')
            ->add('user')
            ->add('server')
            ->add('metadata')
            ->add('sentAt');

        return parent::configureFilters($filters);
    }
}
