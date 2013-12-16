<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\ForumBundle\Manager;

use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Manager\MessageManager;
use Claroline\CoreBundle\Manager\MailManager;
use Claroline\ForumBundle\Entity\Forum;
use Claroline\ForumBundle\Entity\Subject;
use Claroline\ForumBundle\Entity\Message;
use Claroline\ForumBundle\Entity\Notification;
use Claroline\ForumBundle\Entity\Category;
use Claroline\ForumBundle\Event\Log\CreateMessageEvent;
use Claroline\ForumBundle\Event\Log\CreateSubjectEvent;
use Claroline\ForumBundle\Event\Log\DeleteMessageEvent;
use Claroline\ForumBundle\Event\Log\DeleteSubjectEvent;
use Claroline\CoreBundle\Persistence\ObjectManager;
use Claroline\CoreBundle\Pager\PagerFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use JMS\DiExtraBundle\Annotation as DI;

/**
 * @DI\Service("claroline.manager.forum_manager")
 */
class Manager
{
    private $om;
    private $pagerFactory;
    private $dispatcher;
    private $notificationRepo;
    private $subjectRepo;
    private $messageRepo;
    private $forumRepo;
    private $messageManager;
    private $translator;
    private $router;
    private $mailManager;
    private $container;

    /**
     * Constructor.
     *
     * @DI\InjectParams({
     *     "om"             = @DI\Inject("claroline.persistence.object_manager"),
     *     "pagerFactory"   = @DI\Inject("claroline.pager.pager_factory"),
     *     "dispatcher"     = @DI\Inject("event_dispatcher"),
     *     "messageManager" = @DI\Inject("claroline.manager.message_manager"),
     *     "translator"     = @DI\Inject("translator"),
     *     "router"         = @DI\Inject("router"),
     *     "mailManager"    = @DI\Inject("claroline.manager.mail_manager"),
     *     "container"      = @DI\Inject("service_container")
     * })
     */
    public function __construct(
        ObjectManager $om,
        PagerFactory $pagerFactory,
        EventDispatcherInterface $dispatcher,
        MessageManager $messageManager,
        TranslatorInterface $translator,
        RouterInterface $router,
        MailManager $mailManager,
        ContainerInterface $container
    )
    {
        $this->om = $om;
        $this->pagerFactory = $pagerFactory;
        $this->notificationRepo = $om->getRepository('ClarolineForumBundle:Notification');
        $this->subjectRepo = $om->getRepository('ClarolineForumBundle:Subject');
        $this->messageRepo = $om->getRepository('ClarolineForumBundle:Message');
        $this->forumRepo = $om->getRepository('ClarolineForumBundle:Forum');
        $this->dispatcher = $dispatcher;
        $this->messageManager = $messageManager;
        $this->translator = $translator;
        $this->router = $router;
        $this->mailManager = $mailManager;
        $this->container = $container;
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Forum $forum
     * @param \Claroline\CoreBundle\Entity\User $user
     */
    public function subscribe(Forum $forum, User $user)
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setForum($forum);
        $this->om->persist($notification);
        $this->om->flush();
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Forum $forum
     * @param \Claroline\CoreBundle\Entity\User $user
     */
    public function unsubscribe(Forum $forum, User $user)
    {
        $notification = $this->notificationRepo->findOneBy(array('forum' => $forum, 'user' => $user));
        $this->om->remove($notification);
        $this->om->flush();
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Forum $forum
     * @param string $name
     */
    public function createCategory(Forum $forum, $name, $flush = true)
    {
        $category = new Category();
        $category->setName($name);
        $category->setForum($forum);
        $this->om->persist($category);

        if ($flush) {
            $this->om->flush();
        }
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Category $category
     */
    public function deleteCategory(Category $category)
    {
        $this->om->remove($category);
        $this->om->flush();
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Message $message
     */
    public function createMessage(Message $message)
    {
        $this->om->persist($message);
        $this->om->flush();
        $this->dispatch(new CreateMessageEvent($message));
        $this->sendMessageNotification($message, $message->getCreator());
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Message $message
     */
    public function deleteMessage(Message $message)
    {
        $this->om->remove($message);
        $this->om->flush();
        $this->dispatch(new DeleteMessageEvent($message));
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Subject $subject
     */
    public function deleteSubject(Subject $subject)
    {
        $this->om->remove($subject);
        $this->om->flush();
        $this->dispatch(new DeleteSubjectEvent($subject));
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Subject $subject
     */
    public function createSubject(Subject $subject)
    {
        $this->om->persist($subject);
        $this->om->flush();
        $this->dispatch(new CreateSubjectEvent($subject));
    }

    /**
     * @param \Claroline\CoreBundle\Entity\User $user
     * @param \Claroline\ForumBundle\Entity\Forum $forum
     * @return type
     */
    public function hasSubscribed(User $user, Forum $forum)
    {
        $notify = $this->notificationRepo->findBy(array('user' => $user, 'forum' => $forum));

        return count($notify) === 1 ? true: false;
    }

    public function sendMessageNotification(Message $message, User $user)
    {
        $forum = $message->getSubject()->getCategory()->getForum();
        $notifications = $this->notificationRepo->findBy(array('forum' => $forum));
        $users = array();

        foreach ($notifications as $notification) {
            $users[] = $notification->getUser();
        }

        $title = $this->translator->trans(
            'forum_new_message',
            array('%forum%' => $forum->getResourceNode()->getName(), '%subject%' => $message->getSubject()->getTitle()),
            'forum'
        );

        $url =  $link = $this->container->get('request')->server->get('HTTP_ORIGIN') .
                $this->router->generate('claro_forum_subjects', array('category' => $message->getSubject()->getCategory()->getId()));
        $body = "<a href='{$url}'>{$title}</a><hr>{$message->getContent()}";

        $this->mailManager->send($title, $body, $users);

    }

    /**
     * @param integer $subjectId
     */
    public function getSubject($subjectId)
    {
        return $this->subjectRepo->find($subjectId);
    }

    public function getForum($forumId)
    {
        return $this->forumRepo->find($forumId);
    }

    private function dispatch($event)
    {
        $this->dispatcher->dispatch('log', $event);

        return $this;
    }

    public function moveMessage(Message $message, Subject $newSubject)
    {
        $message->setSubject($newSubject);
        $this->om->persist($message);
        $this->om->flush();
    }

    public function moveSubject(Subject $subject, Category $newCategory)
    {
        $subject->setCategory($newCategory);
        $this->om->persist($message);
        $this->om->flush();
    }
}
