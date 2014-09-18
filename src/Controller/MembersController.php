<?php

namespace Bolt\Extension\Bolt\Members\Controller;

use Silex;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Bolt\Extension\Bolt\Members\Extension;
use Bolt\Extension\Bolt\Members\Members;
use Bolt\Extension\Bolt\Members\Entity\Profile;
use Bolt\Extension\Bolt\Members\Entity\Register;
use Bolt\Extension\Bolt\Members\Form\ProfileType;
use Bolt\Extension\Bolt\Members\Form\RegisterType;
use Bolt\Extension\Bolt\Members\MembersRecords;

/**
 * Members extension controller
 *
 * Copyright (C) 2014  Gawain Lynch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Gawain Lynch <gawain.lynch@gmail.com>
 * @copyright Copyright (c) 2014, Gawain Lynch
 * @license   http://opensource.org/licenses/GPL-3.0 GNU Public License 3.0
 */
class MembersController implements ControllerProviderInterface
{
    /**
     * Extension config array
     *
     * @var array
     */
    private $config;

    /**
     * @var Members
     */
    private $members;

    /**
     *
     * @param  Silex\Application           $app
     * @return \Silex\ControllerCollection
     */
    public function connect(Silex\Application $app)
    {
        $this->config = $app[Extension::CONTAINER]->config;
        $this->members = new Members($app);

        /**
         * @var $ctr \Silex\ControllerCollection
         */
        $ctr = $app['controllers_factory'];

        // New member
        $ctr->match('/register', array($this, 'register'))
            ->bind('register')
            ->method('GET|POST');

        // My profile
        $ctr->match('/profile', array($this, 'profileedit'))
            ->bind('profileedit')
            ->method('GET|POST');

        // Member profile
        $ctr->match('/profile/{id}', array($this, 'profileview'))
            ->bind('profileview')
            ->assert('id', '\d*')
            ->method('GET');

        return $ctr;
    }

    /**
     *
     * @param  Silex\Application                        $app
     * @param  Symfony\Component\HttpFoundation\Request $request
     * @return \Twig_Markup
     */
    public function register(Silex\Application $app, Request $request)
    {
        // Add assets to Twig path
        $this->addTwigPath($app);

        // Get redirect that is set for ClientLogin
        $redirect = $app['session']->get('pending');

        // Get session data we need from ClientLogin
        $clientlogin = $app['session']->get('clientlogin');

        // If there is no ClientLogin data in the session, they shouldn't be here
        if (empty($clientlogin)) {
            return new Response('No!', Response::HTTP_FORBIDDEN, array('content-type' => 'text/html'));
        }

        // Expand the JSON array
        $userdata = json_decode($clientlogin['providerdata'], true);
        $userdata['provider'] = $clientlogin['provider'];

        // Create new register form
        $register = new Register();
        $data = array(
            'csrf_protection' => $this->config['csrf'],
            'data' => array(
                'username'    => makeSlug($userdata['displayName'], 32),
                'displayname' => $userdata['displayName'],
                'email'       => $userdata['email']
            )
        );

        $form = $app['form.factory']->createBuilder(new RegisterType(), $register, $data)
                                    ->getForm();

        // Handle the form request data
        $form->handleRequest($request);

        // If we're in a POST, validate the form
        if ($request->getMethod() == 'POST') {
            if ($form->isValid()) {
                // Create new Member record and go back to where we came from
                if ($this->members->addMember($request->get('register'), $userdata)) {
                    // Clear any redirect that ClientLogin has pending
                    $app['session']->remove('pending');
                    $app['session']->remove('clientlogin');

                    // Redirect
                    if (empty($redirect)) {
                        simpleredirect($app['paths']['hosturl']);
                    } else {
                        $returnpage = str_replace($app['paths']['hosturl'], '', $redirect);
                        simpleredirect($returnpage);
                    }
                } else {
                    // Something is wrong here
                }
            }
        }

        $html = $app['render']->render(
            $this->config['templates']['register'], array(
                'form' => $form->createView(),
                'twigparent' => $this->config['templates']['parent']
        ));

        return new \Twig_Markup($html, 'UTF-8');
    }

    /**
     *
     * @param  Silex\Application                        $app
     * @param  Symfony\Component\HttpFoundation\Request $request
     * @return \Twig_Markup
     */
    public function profileedit(Silex\Application $app, Request $request)
    {
        $members = new Members($app);
        $member = $members->isAuth();

        if (! $member) {
            return '';
        } else {
            $member = $members->getMember('id', $member);
            $id = $member['id'];
        }

        // Add assets to Twig path
        $this->addTwigPath($app);

        // Create new register form
        $profile = new Profile();
        $data = array(
            'csrf_protection' => $this->config['csrf'],
            'data' => array(
                'username'    => $member['username'],
                'displayname' => $member['displayname'],
                'email'       => $member['email'],
                'readonly'    => false
            )
        );

        $form = $app['form.factory']->createBuilder(new ProfileType(), $profile, $data)
                                    ->getForm();

        // Handle the form request data
        $form->handleRequest($request);

        // If we're in a POST, validate the form
        if ($request->getMethod() == 'POST') {
            if ($form->isValid()) {
                $reponse = $request->get('profile');

                $records = new MembersRecords($app);
                $records->updateMember($id, array(
                    'displayname' => $reponse['displayname'],
                    'email'       => $reponse['email']
                ));

                simpleredirect($app['paths']['hosturl']);
            }
        }

        $html = $app['render']->render(
            $this->config['templates']['profile_edit'], array(
                'form' => $form->createView(),
                'twigparent' => $this->config['templates']['parent']
        ));

        return new \Twig_Markup($html, 'UTF-8');
    }

    /**
     *
     * @param  Silex\Application                        $app
     * @param  Symfony\Component\HttpFoundation\Request $request
     * @return \Twig_Markup
     */
    public function profileview(Silex\Application $app, Request $request, $id)
    {
        $members = new Members($app);
        $member = $members->getMember('id', $id);

        if (! $member) {
            return '';
        } else {
            $member['avatar'] = $members->getMemberMeta($id, 'avatar');
        }

        // Add assets to Twig path
        $this->addTwigPath($app);

        $html = $app['render']->render(
            $this->config['templates']['profile_view'], array(
                'displayname' => $member['displayname'],
                'email'       => $member['email'],
                'avatar'      => $member['avatar']['value'],
                'twigparent'  => $this->config['templates']['parent']
        ));

        return new \Twig_Markup($html, 'UTF-8');
    }

    /**
     *
     * @param Silex\Application $app
     */
    private function addTwigPath(Silex\Application $app)
    {
        $app['twig.loader.filesystem']->addPath(dirname(dirname(__DIR__)) . '/assets');
    }

}