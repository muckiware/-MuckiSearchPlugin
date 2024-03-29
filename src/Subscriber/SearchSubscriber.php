<?php declare(strict_types=1);
/**
 * MuckiSearchPlugin plugin
 *
 *
 * @category   Muckiware
 * @package    MuckiSearch
 * @copyright  Copyright (c) 2023-2024 by Muckiware
 *
 * @author     Muckiware
 *
 */
namespace MuckiSearchPlugin\Subscriber;

use League\Flysystem\FilesystemException;
use MuckiSearchPlugin\Core\Defaults;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Page\Suggest\SuggestPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchResultLoadedEvent;
use Jenssegers\Agent\Agent;

use MuckiSearchPlugin\Core\Defaults as PluginDefaults;
use MuckiSearchPlugin\Services\Settings as PluginSettings;
use MuckiSearchPlugin\Services\Session as PluginSession;
use MuckiSearchPlugin\Services\SearchTermLog;

class SearchSubscriber implements EventSubscriberInterface
{
    protected array $request;

    public function __construct(
        protected PluginSession $pluginSession,
        protected PluginSettings $pluginSettings,
        protected SearchTermLog $searchTermLog
    )
    {
        $this->request = $_REQUEST;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchResultEvent::class => 'onProductSearch',
            SuggestPageLoadedEvent::class => 'onSearchSuggest'
        ];
    }

    public function onProductSearch(ProductSearchResultEvent $event): void
    {
        $checker = 1;
    }

    /**
     * @throws FilesystemException
     */
    public function onSearchSuggest(SuggestPageLoadedEvent $event)
    {
        $agent = new Agent();
        $agent->setUserAgent($event->getRequest()->server->get('HTTP_USER_AGENT'));

        if($agent->isRobot()) {
            return;
        }

        $this->pluginSession->setSearchTerm(
            $event->getPage()->getSearchTerm(),
            $event->getSalesChannelContext()->getSalesChannelId(),
            $event->getPage()->getSearchResult()->getTotal(),
            $event->getRequest()->server->get('REQUEST_TIME_FLOAT'),
            $event->getRequest()->server->get('HTTP_USER_AGENT')
        );

        $this->searchTermLog->saveSearchLogSessionToFile(
            $this->pluginSession->getSessionId(),
            $this->pluginSession->getCurrentSerializedSearchRequests()
        );
    }
}
