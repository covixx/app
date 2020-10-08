<?php

namespace App\Controller\Admin;

use App\Base\BaseController;
use App\Component\HttpFoundation\NoContentResponse;
use App\Entity\Badge;
use App\Entity\Category;
use App\Manager\BadgeManager;
use App\Manager\CategoryManager;
use App\Model\Csrf;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/categories", name="admin_category_")
 */
class CategoryController extends BaseController
{
    /**
     * @var CategoryManager
     */
    private $categoryManager;

    /**
     * @var BadgeManager
     */
    private $badgeManager;

    /**
     * @param CategoryManager $categoryManager
     * @param BadgeManager    $badgeManager
     */
    public function __construct(CategoryManager $categoryManager, BadgeManager $badgeManager)
    {
        $this->categoryManager = $categoryManager;
        $this->badgeManager    = $badgeManager;
    }

    /**
     * @Route(name="index", path="/")
     * @Template("admin/category/categories.html.twig")
     */
    public function listCategories(Request $request) : array
    {
        $searchForm = $this->createSearchForm($request, 'admin.category.search');

        $categories = $this->getPager(
            $this->categoryManager->getSearchInCategoriesQueryBuilder(
                $searchForm->get('criteria')->getData()
            )
        );

        return [
            'categories' => $categories,
            'search'     => $searchForm->createView(),
        ];
    }

    /**
     * @Route(name="form", path="/form-for-{id}", defaults={"id" = null})
     */
    public function categoryForm(Request $request, Category $category = null) : Response
    {
        if (!$category) {
            $category = new Category();
        }

        $form = $this->createFormBuilder($category)
                     ->add('name', TextType::class, [
                         'label' => 'admin.category.form.name',
                     ])
                     ->add('priority', NumberType::class, [
                         'label' => 'admin.category.form.priority',
                     ])
                     ->add('submit', SubmitType::class, [
                         'attr' => [
                             'class' => 'd-none',
                         ],
                     ])
                     ->getForm()
                     ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->categoryManager->save($category);

            return $this->json([
                'saved' => true,
                'id'    => $category->getId(),
                'view'  => $this->renderView('admin/category/category.html.twig', [
                    'category' => $category,
                ]),
            ]);
        }

        return $this->json([
            'saved' => false,
            'title' => $category->getId() ? $this->trans('admin.category.edit') : $this->trans('admin.category.create'),
            'body'  => $this->renderView('widget/form.html.twig', [
                'form' => $form->createView(),
            ]),
        ]);
    }

    /**
     * @Route(name="delete", path="/delete-category-{id}/{token}"))
     */
    public function deleteCategory(Category $category, Csrf $token)
    {
        $this->categoryManager->remove($category);

        return new NoContentResponse();
    }

    /**
     * @Route(name="badges", path="/list-badges-in-category-{id}")
     */
    public function listBadgeInCategory(Category $category)
    {
        return $this->json([
            'title' => $this->trans('admin.category.badges_list', [
                '%name%' => $category->getName(),
            ]),
            'body'  => $this->renderView('admin/category/badges_in_category.html.twig', [
                'category' => $category,
            ]),
        ]);
    }

    /**
     * @Route(name="add_badge", path="/add-badge-in-category-{id}/{token}"))
     */
    public function addBadgeInCategory(Request $request, Category $category, Csrf $token)
    {
        if (!$badge = $this->badgeManager->find($request->get('badge'))) {
            throw $this->createNotFoundException();
        }

        $category->addBadge($badge);

        $this->badgeManager->save($badge);

        return $this->json([
            'body' => $this->renderView('admin/category/badges_in_category.html.twig', [
                'category' => $category,
            ]),
        ]);
    }

    /**
     * @Route(name="delete_badge", path="/delete-badge-{badgeId}-in-category-{categoryId}/{token}"))
     * @Entity("category", expr="repository.find(categoryId)")
     * @Entity("badge", expr="repository.find(badgeId)")
     */
    public function deleteBadgeInCategory(Category $category, Badge $badge, Csrf $token)
    {
        $category->removeBadge($badge);

        $this->badgeManager->save($badge);

        return new NoContentResponse();
    }

    private function createSearchForm(Request $request, string $label) : FormInterface
    {
        return $this->createFormBuilder(null, ['csrf_protection' => false])
                    ->setMethod('GET')
                    ->add('criteria', TextType::class, [
                        'label'    => $label,
                        'required' => false,
                    ])
                    ->add('submit', SubmitType::class, [
                        'label' => 'base.button.search',
                    ])
                    ->getForm()
                    ->handleRequest($request);
    }
}