<?php
/**
 * @package        Joomla
 * @subpackage     Membership Pro
 * @author         Tuan Pham Ngoc
 * @copyright      Copyright (C) 2012 - 2020 Ossolution Team
 * @license        GNU/GPL, see LICENSE.php
 */
defined('_JEXEC') or die;

use Joomla\Registry\Registry;

class plgSystemOSMembershipBuyArticles extends JPlugin
{
	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 */
	protected $app;
	/**
	 * Database object
	 *
	 * @var JDatabaseDriver
	 */
	protected $db;

	/**
	 * Whether the plugin should be run when events are triggered
	 *
	 * @var bool
	 */
	protected $canRun;

	/**
	 * Constructor
	 *
	 * @param object &$subject The object to observe
	 * @param array   $config  An optional associative array of configuration settings.
	 */
	public function __construct($subject, array $config = [])
	{
		parent::__construct($subject, $config);

		$this->canRun = file_exists(JPATH_ADMINISTRATOR . '/components/com_osmembership/osmembership.php');

		if ($this->canRun)
		{
			require_once JPATH_ADMINISTRATOR . '/components/com_osmembership/loader.php';
		}
	}

	/**
	 * Hide fulltext of article to none-subscribers
	 *
	 * @param     $context
	 * @param     $row
	 * @param     $params
	 * @param int $page
	 *
	 * @return bool|void
	 */
	public function onContentPrepare($context, &$row, &$params, $page = 0)
	{
		if (!$this->canRun)
		{
			return true;
		}

		if ($this->app->isClient('administrator'))
		{
			return true;
		}

		$option = $this->app->input->getCmd('option');

		if ($option != 'com_content')
		{
			return true;
		}

		$articleFields = [
			'introtext',
			'fulltext',
			'catid',
		];

		foreach ($articleFields as $articleField)
		{
			if (!property_exists($row, $articleField))
			{
				return;
			}
		}

		if ($this->params->get('allow_search_engine', 0) == 1 && $this->app->client->robot)
		{
			return;
		}

		if (!is_object($row))
		{
			return;
		}


		if ($this->isArticleReleased($row->id))
		{
			return;
		}
        
        if(isset($_GET['addBoughtArticle']) && $_GET['addBoughtArticle'] == $row->id) {
            $this->addBoughtArticle($row->id);
        }

        $activePlanIds = OSMembershipHelperSubscription::getActiveMembershipPlans();
		$planIds = $this->getBuyPlanIds($row->id);
		$boughtArticleIds = $this->getBoughtArticleIds();

        if (count(array_intersect($activePlanIds, $planIds)) > 0 && !in_array($row->id, $boughtArticleIds))
        {
            $message     = OSMembershipHelper::getMessages();
            $fieldSuffix = OSMembershipHelper::getFieldSuffix();

            /*if (strlen($message->{'content_restricted_message' . $fieldSuffix}))
            {
                $msg = $message->{'content_restricted_message' . $fieldSuffix};
            }
            else
            {
                $msg = $message->content_restricted_message;
            }

            $msg = str_replace('[PLAN_TITLES]', $this->getPlanTitles($planIds), $msg);*/
            
            if ($this->getRemainingBuyArticles() > 0) {
                $msg = 'Your current plan allows you to view [REMAINING_BUY_ARTICLES] more fighters. Click <a href="[ADD_BUY_ARTICLE]"><strong>here</strong></a> to add this fighter.';
            } else {
                $msg = 'Your current plan does not allow you to add any more fighters. Please purchase a new plan.';
            }

            //$redirectUrl = $this->findRedirectUrl($planIds);

            // Add the required plans to redirect URL
            //$redirectUri = JUri::getInstance($redirectUrl);
            //$redirectUri->setVar('filter_plan_ids', implode(',', $planIds));

            // Store URL of this page to redirect user back after user logged in if they have active subscription of this plan
            //$session = JFactory::getSession();
            //$session->set('osm_return_url', JUri::getInstance()->toString());
            //$session->set('required_plan_ids', $planIds);

            //$loginUrl = JRoute::_('index.php?option=com_users&view=login&return=' . base64_encode(JUri::getInstance()->toString()), false);
            
            $addUri = JUri::getInstance();
            $addUri->setVar( 'addBoughtArticle', $row->id );
            
            //$msg = str_replace('[SUBSCRIPTION_URL]', $redirectUri->toString(), $msg);
            //$msg = str_replace('[LOGIN_URL]', $loginUrl, $msg);
            $msg = str_replace('[REMAINING_BUY_ARTICLES]', $this->getRemainingBuyArticles(), $msg);
            $msg = str_replace('[ADD_BUY_ARTICLE]', $addUri, $msg);

            $t[]       = $row->introtext;
            $t[]       = '<div class="text-info">' . $msg . '</div>';
            $row->text = implode(' ', $t);

            if ($row->params instanceof Registry)
            {
                $row->params->set('show_readmore', 0);
            }
		}

		return true;
	}

	/**
	 * Display list of articles on profile page
	 *
	 * @param OSMembershipTableSubscriber $row
	 *
	 * @return array
	 */
	public function onProfileDisplay($row)
	{
		if (!$this->params->get('display_articles_in_profile'))
		{
			return;
		}

		ob_start();
		$this->displayArticles($row);

		$form = ob_get_clean();

		return ['title' => JText::_('OSM_MY_ARTICLES'),
		        'form'  => $form,
		];
	}

	/**
	 * Check if article released
	 *
	 * @param int $articleId
	 *
	 * @return bool
	 */
	private function isArticleReleased($articleId)
	{
		if (!$this->params->get('release_article_older_than_x_days', 0))
		{
			return false;
		}

		$query = $this->db->getQuery(true)
			->select('*')
			->from('#__content')
			->where('id = ' . (int) $articleId);
		$this->db->setQuery($query);
		$article = $this->db->loadObject();

		if ($article->publish_up && $article->publish_up != $this->db->getNullDate())
		{
			$publishedDate = $article->publish_up;
		}
		else
		{
			$publishedDate = $article->created;
		}

		$today         = JFactory::getDate();
		$publishedDate = JFactory::getDate($publishedDate);
		$numberDays    = $publishedDate->diff($today)->days;

		// This article is older than configured number of days, it can be accessed for free
		if ($today >= $publishedDate && $numberDays >= $this->params->get('release_article_older_than_x_days'))
		{
			return true;
		}

		return false;
	}

	/**
	 * The the Ids of the plans which users can subscribe for to access to the given article
	 *
	 * @param int $articleId
	 *
	 * @return array
	 */
	private function getBuyPlanIds($articleId)
	{
		$planIds = [];
        $query = $this->db->getQuery(true);
		$query->clear()
			->select('id')
			->from('#__osmembership_plans')
			->where('published = 1 and title LIKE "Work with %"');
		$this->db->setQuery($query);
		$plans = $this->db->loadObjectList();

		foreach ($plans as $plan)
		{
            $planIds[] = $plan->id;
		}

		return $planIds;
	}

	/**
	 * Get imploded titles of the given plans
	 *
	 * @param array $planIds
	 *
	 * @return string
	 */
	private function getPlanTitles($planIds)
	{
		$query = $this->db->getQuery(true);
		$query->select('title')
			->from('#__osmembership_plans')
			->where('id IN (' . implode(',', $planIds) . ')')
			->where('published = 1')
			->order('ordering');
		$this->db->setQuery($query);

		return implode(' ' . JText::_('OSM_OR') . ' ', $this->db->loadColumn());
	}
    
    /**
     * Get articles the user has added to his active subscription
     */
    private function getBoughtArticleIds()
    {
        $activeSubscriptions = $this->getActiveSubscriptions();
        $boughtArticles = [];
        foreach ($activeSubscriptions as $subscription) {
            $params = $subscription->params;
            if ($params) {
                $params = json_decode($params, true);
            } else {
                $params = [];
            }
            if (!isset($params['bought_articles'])) {
                $params['bought_articles'] = [];
            }
            
            foreach ($params['bought_articles'] as $curArticleId) {
                $boughtArticles[] = $curArticleId;
            }
        }
        return $boughtArticles;
    }
    
    /* The number of articles remaining for the user based on the total minus the bought ones */
    private function getRemainingBuyArticles()
    {
        return $this->getTotalBuyArticles() - count($this->getBoughtArticleIds());
    }
    
    /* The total number of articles in the user's subscription plan */
    private function getTotalBuyArticles()
    {
        $activePlans = OSMembershipHelperSubscription::getActiveMembershipPlans();
        $totalArticles = 0;
        foreach ($activePlans as $curPlan) {
            if ($curPlan == 0) {
                continue;
            }
            
            $query = $this->db->getQuery(true);
            $query->select('alias')
                ->from('#__osmembership_plans')
                ->where('id = ' . $curPlan)
                ->where('published = 1')
                ->order('ordering');
            $this->db->setQuery($query);
            $alias = $this->db->loadColumn();
            foreach ($alias as $curAlias) {
                $aliasParts = explode('-', $curAlias);
                $totalArticles = $totalArticles + intval($aliasParts[1]);
            }
        }
        return $totalArticles;
    }
    
    private function getActiveSubscriptions()
    {
        $userId = (int) JFactory::getUser()->get('id');
        $activePlans = OSMembershipHelperSubscription::getActiveMembershipPlans();
        $subscriptions = [];
        foreach ($activePlans as $curPlan) {
            if ($curPlan == 0) {
                continue;
            }
            
            // Get the subscription from DB
            $query = $this->db->getQuery(true);
			$query->select('*')
				->from('#__osmembership_subscribers AS a')
				->where('a.user_id = ' . $userId)
                ->where('a.plan_id = ' . $curPlan)
				->order('a.id DESC')
                ->setLimit(1);
            $this->db->setQuery($query);
            $subscription = $this->db->loadObjectList();
            foreach ($subscription as $curSubscription) {
                $subscriptions[] = $curSubscription;
            }
        }
        
        return $subscriptions;
    }
    
    private function addBoughtArticle($articleId)
    {
        if ($this->getRemainingBuyArticles() <= 0) {
            echo 'No articles remaining for this plan'; die();
        }
        $boughtArticles = $this->getBoughtArticleIds();
        if (in_array($articleId, $boughtArticles)) {
            echo 'This article has already been bought for this subscription'; die();
        }
        
        $activeSubscriptions = $this->getActiveSubscriptions();
        $boughtArticles = [];
        foreach ($activeSubscriptions as $subscription) {
            $params = $subscription->params;
            if ($params) {
                $params = json_decode($params, true);
            } else {
                $params = [];
            }
            if (!isset($params['bought_articles'])) {
                $params['bought_articles'] = [];
            }
            $params['bought_articles'][] = $articleId;
            
            $query = $this->db->getQuery(true);
            // Fields to update.
            $fields = array(
                $this->db->quoteName('params') . ' = \'' . json_encode($params) . '\''
            );
            // Conditions for which records should be updated.
            $conditions = array(
                $this->db->quoteName('id') . ' = ' . $subscription->id
            );
            $query->update($this->db->quoteName('#__osmembership_subscribers'))->set($fields)->where($conditions);
            $this->db->setQuery($query);
            $this->db->execute();
            
            // Redirect to subscription page to allow users to subscribe or logging in
            $redirectUri = JUri::getInstance();
            $redirectUri->delVar( 'addBoughtArticle' );

            //$this->app->enqueueMessage('Article added');
            $this->app->redirect($redirectUri->toString());

            break; // Only add the bought article to the first found subscription
        }
    }

	/**
	 * Find the best match URL which users can access to subscribe for the one of the given plans
	 *
	 * @param array $planIds
	 *
	 * @return mixed|string
	 */
	private function findRedirectUrl($planIds)
	{
		// Try to find the best redirect URL
		$redirectUrl = OSMembershipHelper::getRestrictionRedirectUrl($planIds);

		if (empty($redirectUrl))
		{
			$redirectUrl = $this->params->get('redirect_url', OSMembershipHelper::getViewUrl(['categories', 'plans', 'plan', 'register']));
		}

		if (!$redirectUrl)
		{
			$redirectUrl = JUri::root();
		}

		return $redirectUrl;
	}

	/**
	 * Display articles which subscriber can access to
	 *
	 * @param OSMembershipTableSubscriber $row
	 *
	 * @throws Exception
	 */
	private function displayArticles($row)
	{
		$query         = $this->db->getQuery(true);
		$boughtArticleIds = $this->getBoughtArticleIds();

		$items = [];

		if (count($boughtArticleIds) > 1)
		{
			$query->clear()
				->select('a.id, a.catid, a.title, a.alias, a.hits, c.title AS category_title')
				->from('#__content AS a')
				->innerJoin('#__categories AS c ON a.catid = c.id')
				->where('a.id IN (' . implode(',', $boughtArticleIds) . ')')
				->where('a.state = 1')
				->order('a.ordering');

			if (JLanguageMultilang::isEnabled())
			{
				$query->where('a.language IN (' . $this->db->quote(JFactory::getLanguage()->getTag()) . ',' . $this->db->quote('*') . ', "")');
			}

			$this->db->setQuery($query);

			$items = array_merge($items, $this->db->loadObjectList());
		}

		if (empty($items))
		{
			return;
		}

		$bootstrapHelper = OSMembershipHelperBootstrap::getInstance();
		$centerClass     = $bootstrapHelper->getClassMapping('center');
		?>
        <table class="adminlist <?php echo $bootstrapHelper->getClassMapping('table table-striped table-bordered'); ?>"
               id="adminForm">
            <thead>
            <tr>
                <th class="title"><?php echo JText::_('OSM_TITLE'); ?></th>
                <th class="title"><?php echo JText::_('OSM_CATEGORY'); ?></th>
                <th class="<?php echo $centerClass; ?>"><?php echo JText::_('OSM_HITS'); ?></th>
            </tr>
            </thead>
            <tbody>
			<?php
			JLoader::register('ContentHelperRoute', JPATH_ROOT . '/components/com_content/helpers/route.php');

			$displayedArticleIds = [];

			foreach ($items as $item)
			{
				if (in_array($item->id, $displayedArticleIds))
				{
					continue;
				}

				$displayedArticleIds[] = $item->id;

				$articleLink = JRoute::_(ContentHelperRoute::getArticleRoute($item->id, $item->catid));
				?>
                <tr>
                    <td><a href="<?php echo $articleLink ?>"><?php echo $item->title; ?></a></td>
                    <td><?php echo $item->category_title; ?></td>
                    <td class="<?php echo $centerClass; ?>">
						<?php echo $item->hits; ?>
                    </td>
                </tr>
				<?php
			}
			?>
            </tbody>
        </table>
		<?php
	}
}
