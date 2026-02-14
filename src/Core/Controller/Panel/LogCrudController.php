<?php


namespace App\Core\Controller\Panel;

use App\Core\Entity\Log;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class LogCrudController extends AbstractPanelController
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
        return Log::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $this->fields = [
            IdField::new('id')->hideOnForm(),
            TextField::new('actionId', $this->translator->trans('pteroca.crud.log.action'))
                ->setDisabled()
                ->formatValue(fn ($value) => $this->translator->trans('pteroca.actions.' . $value)),
            CodeEditorField::new('details', $this->translator->trans('pteroca.crud.log.details'))
                ->setDisabled()
                ->formatValue(fn ($value) => $value === '[]' ? '' : json_encode(json_decode($value), JSON_PRETTY_PRINT)),
            TextField::new('ipAddress', $this->translator->trans('pteroca.crud.log.ip_address')),
            DateTimeField::new('createdAt', $this->translator->trans('pteroca.crud.log.created_at'))
                ->setDisabled(),
            AssociationField::new('user', $this->translator->trans('pteroca.crud.log.user'))
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
        $this->appendCrudTemplateContext(CrudTemplateContextEnum::LOG->value);

        $crud
            ->setEntityLabelInSingular($this->translator->trans('pteroca.crud.log.log'))
            ->setEntityLabelInPlural($this->translator->trans('pteroca.crud.log.logs'))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();

        return parent::configureCrud($crud);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add('actionId')
            ->add('details')
            ->add('ipAddress')
            ->add('createdAt')
            ->add('user');

        return parent::configureFilters($filters);
    }
}
