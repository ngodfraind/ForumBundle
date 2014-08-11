<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\ForumBundle\Installation\Updater;

use Symfony\Component\DependencyInjection\ContainerInterface;

class Updater030100
{
    private $container;
    private $em;
    private $logger;
    private $ut;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->ut = $container->get('claroline.utilities.misc');
    }

    public function postUpdate()
    {        
        $this->log('Adding hashname...');
        $forums = $this->em->getRepository('ClarolineForumBundle:Forum')->findAll();
        $categories = $this->em->getRepository('ClarolineForumBundle:Category')->findAll();
        $subjects = $this->em->getRepository('ClarolineForumBundle:Subject')->findAll();
        $messages = $this->em->getRepository('ClarolineForumBundle:Message')->findAll();
        
        $counter = 0;
        $this->postUpdateCategory($categories, $counter);
        $this->postUpdateSubjects($subjects, $counter);
        $this->postUpdateMessage($messages, $counter);

        $this->em->flush();
    }
    
    private function postUpdateCategory($categories, $counter)
    {
        $this->log('Adding category hashname...');
        foreach ($categories as $cat) {
            $subjects = $cat->getSubjects();
            $cat->setHashname($this->ut->generateGuid());
            $counter ++;
            $this->testFlush($counter);
        }
    }
    
    private function postUpdateSubjects($subjects, $counter) 
    {        
        $this->log('Adding subjects hashname...');
        foreach ($subjects as $subject) {
            $subject->setHashname($this->ut->generateGuid());
            $messages = $subject->getMessages();
            $counter ++;
            $this->testFlush($counter);
        }
    }
    
    private function postUpdateMessage($messages, $counter)
    {
        $this->log('Adding message hashname...');
        foreach ($messages as $msg) {
            $msg->setHashname($this->ut->generateGuid());
            $counter ++;
        }
    }

    private function testFlush($i)
    {
        if ($i % 50 === 0) {
            $this->em->flush();
        }
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    private function log($message)
    {
        if ($log = $this->logger) {
            $log('    ' . $message);
        }
    }
}
