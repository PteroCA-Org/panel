<?php


namespace App\Core\Controller\Panel;

use App\Core\Entity\ServerLog;
use App\Core\Enum\CrudTemplateContextEnum;
use App\Core\Service\Crud\PanelCrudService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ServerLogCrudController extends AbstractPanelController
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
        return ServerLog::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('actionId', $this->translator->trans('pteroca.crud.log.action'))
                ->setDisabled()
            ->formatValue(fn ($value) => $this->translator->trans('pteroca.server_actions.' . $value)),
            CodeEditorField::new('details', $this->translator->trans('pteroca.crud.log.details'))
                ->setDisabled()
                ->formatValue(fn ($value) => $value === '[]' ? '' : json_encode(json_decode($value), JSON_PRETTY_PRINT)),
            DateTimeField::new('createdAt', $this->translator->trans('pteroca.crud.log.created_at'))
                ->setDisabled(),
            AssociationField::new('user', $this->translator->trans('pteroca.crud.log.user'))
                ->setDisabled(),
            AssociationField::new('server', $this->translator->trans('pteroca.crud.log.server'))
                ->setDisabled(),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return parent::configureActions($actions);
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::SERVER_LOG->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.log.server_log'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.log.server_logs'))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('actionId')
            ->add('details')
            ->add('createdAt')
            ->add('user')
            ->add('server')
        ;

        return parent::configureFilters($filters);
    }
}
