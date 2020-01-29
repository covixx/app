<?php

namespace App\Controller;

use App\Entity\Volunteer;
use App\Manager\LocaleManager;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends Controller
{
    private $locale;

    /**
     * HomeController constructor.
     *
     * @param LocaleManager $locale
     */
    public function __construct(LocaleManager $locale)
    {
        $this->locale = $locale;
    }

    /**
     * @Route(name="home")
     */
    public function home()
    {
        if (!$this->isGranted('ROLE_TRUSTED')) {
            return $this->redirectToRoute('password_login_not_trusted');
        }

        return $this->render('home.html.twig');
    }

    /**
     * @Route("/infos/{nivol}", name="infos")
     */
    public function infos(Volunteer $volunteer)
    {
        return $this->render('infos.html.twig', [
            'volunteer' => $volunteer,
        ]);
    }

    /**
     * @Route("/locale/{locale}", name="locale")
     */
    public function locale(string $locale)
    {
        $this->locale->save($locale);

        return $this->redirectToRoute('home');
    }
}
