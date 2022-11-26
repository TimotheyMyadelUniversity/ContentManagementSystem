<?php
/**
 * @package   Nicepage Website Builder
 * @author    Nicepage https://www.nicepage.com
 * @copyright Copyright (c) 2016 - 2019 Nicepage
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPLv2 or later
 */

namespace NP\Editor;

defined('_JEXEC') or die;

use \JLoader, \JFilterOutput, \JTable, \JDate, \JRegistry, \JFactory;

JLoader::register('Nicepage_Data_Mappers', JPATH_ADMINISTRATOR . '/components/com_nicepage/tables/mappers.php');
/**
 * Class MenuItemsSaver
 */
class MenuItemsSaver
{
    private $_menuData;

    /**
     * MenuItemsSaver constructor.
     *
     * @param array $menuData Menu data
     */
    public function __construct($menuData)
    {
        $this->_menuData = $menuData;
    }

    /**
     * Process menu data
     *
     * @return array
     */
    public function save() {
        if (!$this->_menuData) {
            return array(
                'status' => 'error',
                'message' => 'No data to save',
            );
        }
        $menuItems = json_decode($this->_menuData['menuItems'], true);
        $menuOptions = $this->_menuData['menuOptions'];

        $siteMenuId = isset($menuOptions['siteMenuId']) ? $menuOptions['siteMenuId'] : '';
        $translationsSiteMenuId = isset($menuOptions['translationsSiteMenuId']) ? $menuOptions['translationsSiteMenuId'] : array();

        $originalMenuIds = isset($menuOptions['menuIds']) ? $menuOptions['menuIds'] : array();
        $originalTranslationsMenuIds = isset($menuOptions['translationsMenuIds']) ? $menuOptions['translationsMenuIds'] : array();

        $menusMapper = \Nicepage_Data_Mappers::get('menu');
        $menuItemsMapper = \Nicepage_Data_Mappers::get('menuItem');

        $home = $menuItemsMapper->find(array('home' => 1, 'menu' => $siteMenuId));

        if (count($home) < 1) {
            return array(
                'status' => 'error',
                'message' => 'Default menu not found',
            );
        }
        $homeItem = $home[0];

        $rndMenu = $menusMapper->create();
        $rndMenu->title = $rndMenu->menutype = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 10);
        $status = $menusMapper->save($rndMenu);
        if (is_string($status)) {
            trigger_error($status, E_USER_ERROR);
        }
        $rndItem = $menuItemsMapper->create();
        $rndItem->home = '1';
        $rndItem->checked_out = $homeItem->checked_out;
        $rndItem->menutype = $rndMenu->menutype;
        $rndItem->alias = $rndItem->title = $rndMenu->menutype;
        $rndItem->link = 'index.php?option=com_content&view=article&id=';
        $rndItem->type = 'component';
        $rndItem->component_id = '22';

        $registry = new JRegistry();
        $registry->loadArray(array());
        $rndItem->params = $registry->toString();

        $status = $menuItemsMapper->save($rndItem);
        if (is_string($status)) {
            trigger_error($status, E_USER_ERROR);
        }

        $oldMenuItems = $menuItemsMapper->find(array('menu' => $siteMenuId));
        $oldMenuLinks = array();
        foreach ($oldMenuItems as $oldMenuItem) {
            array_push($oldMenuLinks, $oldMenuItem->link);
            $menuItemsMapper->delete($oldMenuItem->id);
        }

        $translationsAllMenuItems = array();
        $translationsOldMenuLinks = array();
        if (count($translationsSiteMenuId) > 0) {
            foreach ($translationsSiteMenuId as $lang => $translationsSiteMenuIdItem) {
                $translationsOldMenuItems = $menuItemsMapper->find(array('menu' => $translationsSiteMenuIdItem));
                foreach ($translationsOldMenuItems as $translationsOldMenuItem) {
                    if (!isset($translationsOldMenuLinks[$lang])) {
                        $translationsOldMenuLinks[$lang] = array();
                    }
                    array_push($translationsOldMenuLinks[$lang], $translationsOldMenuItem->link);
                    $menuItemsMapper->delete($translationsOldMenuItem->id);
                }
                $translationsAllMenuItems[$lang] = $translationsOldMenuItems;
            }
        }

        $markedHomeItem = false;
        foreach ($menuItems as &$menuItem) {
            $menuItem['home'] = '0';
            if ($homeItem->link == $menuItem['href']) {
                $markedHomeItem = true;
                $menuItem['home'] = '1';
            }
        }

        $level = 0;
        $parentIds = array();
        $joomlaMenuIds = array();
        $translationsMenuIds = array();
        $translationsMarkedHomeItem = array();
        $translationsParentIds = array();
        foreach ($menuItems as $index => $itemData) {
            $options = array(
                'index' => $index,
                'menuItems' => $menuItems,
                'parentIds' => $parentIds,
                'level' => $level,
                'markedHomeItem' => $markedHomeItem,
                'itemData' => $itemData,
                'oldMenuLinks' => $oldMenuLinks,
                'oldMenuItems' => $oldMenuItems,
                'menutype' => $siteMenuId,
            );
            $mainRetData = $this->_updateMenuItem($options);
            array_push($joomlaMenuIds, $mainRetData['id']);
            $parentIds = $mainRetData['parentIds'];
            $markedHomeItem = $mainRetData['markedHomeItem'];
            $menuItems[$index]['joomla_id'] = $mainRetData['id'];

            $langs = $itemData['langs'];
            foreach ($langs as $lang => $json) {
                $lang = str_replace('data-lang-', '', $lang);

                if (array_key_exists($lang, $translationsSiteMenuId)) {
                    if (!isset($translationsMarkedHomeItem[$lang])) {
                        $translationsMarkedHomeItem[$lang] = false;
                    }
                    if (!isset($translationsParentIds[$lang])) {
                        $translationsParentIds[$lang] = array();
                    }
                    $options = array(
                        'index' => $index,
                        'menuItems' => $menuItems,
                        'parentIds' => $translationsParentIds[$lang],
                        'level' => $level,
                        'markedHomeItem' => $translationsMarkedHomeItem[$lang],
                        'itemData' => $itemData,
                        'oldMenuLinks' => $translationsOldMenuLinks[$lang],
                        'oldMenuItems' => $translationsAllMenuItems[$lang],
                        'menutype' => $translationsSiteMenuId[$lang],
                        'lang' => $lang,
                        'json' => $json,
                    );
                    $retData = $this->_updateMenuItem($options);
                    if (!isset($translationsMenuIds[$lang])) {
                        $translationsMenuIds[$lang] = array();
                    }
                    array_push($translationsMenuIds[$lang], $retData['id']);
                    $translationsParentIds[$lang] = $retData['parentIds'];
                    $translationsMarkedHomeItem[$lang] = $retData['markedHomeItem'];
                    $menuItems[$index][$lang] = $retData['id'];
                }
            }
            $level = $mainRetData['level'];
        }

        if ($rndMenu) {
            $status = $menusMapper->delete($rndMenu->id);
            if (is_string($status)) {
                trigger_error($status, E_USER_ERROR);
            }
        }

        $modules = \Nicepage_Data_Mappers::get('module');
        $moduleList = $modules->find(array('scope' => 'site', 'module' => 'mod_menu'));
        foreach ($moduleList as $moduleListItem) {
            $registry = new JRegistry();
            $registry->loadString($moduleListItem->params);
            $params = $registry->toArray();
            $menutype = $params['menutype'];
            if ($menutype === $siteMenuId) {
                $modules->enableOn($moduleListItem->id, $joomlaMenuIds);
            }
            if (in_array($menutype, $translationsSiteMenuId)) {
                $key = array_search($menutype, $translationsSiteMenuId);
                $modules->enableOn($moduleListItem->id, $translationsMenuIds[$key]);
            }
        }

        return array(
            'result' => 'done',
            'menuOptions' => array(
                'menuIds' => $joomlaMenuIds,
                'translationsMenuIds' => $translationsMenuIds,
            )
        );
    }

    /**
     * Update menu options
     *
     * @param array $options Menu Item options
     *
     * @return array
     */
    private function _updateMenuItem($options) {
        $menuItemsMapper = \Nicepage_Data_Mappers::get('menuItem');
        $index = $options['index'];
        $menuItems = $options['menuItems'];
        $parentIds = $options['parentIds'];
        $level = $options['level'];
        $markedHomeItem = $options['markedHomeItem'];
        $itemData = $options['itemData'];
        $oldMenuLinks = $options['oldMenuLinks'];
        $oldMenuItems = $options['oldMenuItems'];
        $menutype = $options['menutype'];

        $lang = isset($options['lang']) ? $options['lang'] : '';
        $jsonData = isset($options['json']) ? json_decode($options['json'], true) : '';

        $itemLevel = $itemData['level'];
        $href = $itemData['href'];
        $title =  $itemData['name'];

        if ($jsonData) {
            $href = $jsonData['href'];
            $title = $jsonData['content'];
        }

        $foundKey = array_search($href, $oldMenuLinks);
        if ($foundKey !== false) {
            $item = $oldMenuItems[$foundKey];
            $item->id = null;
        } else {
            $item = $menuItemsMapper->create();
            $item->menutype = $menutype;

            $type = 'custom';
            if (preg_match('/^index\.php\?option=com_content&view=article&id=\d+/', $href)) {
                $type = 'single-article';
            } else if (preg_match('/^index\.php\?option=com_content&view=category&layout=blog&id=\d+/', $href)) {
                $type = 'category-blog-layout';
            }
            switch ($type) {
            case 'single-article':
                $item->link = $href;
                $item->type = 'component';
                $item->component_id = '22';
                $params = $this->getSingleArticleParams();
                break;
            case 'category-blog-layout':
                $item->link = $href;
                $item->type = 'component';
                $item->component_id = '22';
                $params = $this->getCategoryBlogParams();
                break;
            default:
                $item->link = $href;
                $item->type = 'url';
                $item->component_id = '0';
                $params = array
                (
                    'menu-anchor_title' => '',
                    'menu-anchor_css' => '',
                    'menu_image' => '',
                    'menu_text' => 1
                );
            }
            $registry = new JRegistry();
            $registry->loadArray($params);
            $item->params = $registry->toString();
        }
        $item->browserNav = isset($itemData['blank']) && $itemData['blank'] ? '1' : '0';
        $item->home = $itemData['home'];
        $item->title = $title;

        if (JFactory::getConfig()->get('unicodeslugs') == 1) {
            $alias = JFilterOutput::stringURLUnicodeSlug($item->title);
        } else {
            $alias = JFilterOutput::stringURLSafe($item->title);
        }
        if (JTable::getInstance('Menu')->load(array('alias' => $alias))) {
            $date = new JDate();
            $alias = $date->format('Y-m-d-H-i-s');
        }
        $item->alias = $alias;

        if (!$markedHomeItem && preg_match('/index\.php\?option=/', $href)) {
            $item->home = 1;
            $markedHomeItem = true;
        }

        if ($itemLevel == 0) {
            $parentId = 1;
            $parentIds = array('0' => 1);
        } else if ($itemLevel > $level) {
            $parentId = $menuItems[$index - 1][$lang ?: 'joomla_id'];
            $parentIds[$itemLevel] = $parentId;
        } else {
            $parentId = $parentIds[$itemLevel];
        }
        $level = $itemLevel;

        $item->setLocation($parentId, 'last-child');


        $status = $menuItemsMapper->save($item);
        if (is_string($status)) {
            trigger_error($status, E_USER_ERROR);
        }
        return array(
            'id' => $item->id,
            'parentIds' => $parentIds,
            'level' => $level,
            'markedHomeItem' => $markedHomeItem,
        );
    }
    /**
     * Get article parameters
     *
     * @return array
     */
    public function getSingleArticleParams()
    {
        return array(
            'show_title' => '1',
            'link_titles' => '',
            'show_intro' => '',
            'show_category' => '0',
            'link_category' => '',
            'show_parent_category' => '0',
            'link_parent_category' => '',
            'show_author' => '0',
            'link_author' => '',
            'show_create_date' => '0',
            'show_modify_date' => '0',
            'show_publish_date' => '0',
            'show_item_navigation' => '0',
            'show_vote' => '0',
            'show_icons' => '0',
            'show_print_icon' => '0',
            'show_email_icon' => '0',
            'show_hits' => '0',
            'show_noauth' => '',
            'menu-anchor_title' => '',
            'menu-anchor_css' => '',
            'menu_image' => '',
            'menu_text' => '1',
            'page_title' => '',
            'show_page_heading' => '0',
            'page_heading' => '',
            'pageclass_sfx' => '',
            'menu-meta_description' => '',
            'menu-meta_keywords' => '',
            'robots' => '',
            'secure' => '0',
            'page_title' => ''
        );
    }

    /**
     * Get blog parameters
     *
     * @return array
     */
    public function getCategoryBlogParams()
    {
        return array(
            'layout_type' => 'blog',
            'show_category_title' => '',
            'show_description' => '',
            'show_description_image' => '',
            'maxLevel' => '',
            'show_empty_categories' => '',
            'show_no_articles' => '',
            'show_subcat_desc' => '',
            'show_cat_num_articles' => '',
            'page_subheading' => '',
            'num_leading_articles' => '0',
            'num_intro_articles' => '4',
            'num_columns' => '1',
            'num_links' => '',
            'multi_column_order' => '',
            'show_subcategory_content' => '',
            'orderby_pri' => '',
            'orderby_sec' => 'order',
            'order_date' => '',
            'show_pagination' => '',
            'show_pagination_results' => '',
            'show_title' => '',
            'link_titles' => '',
            'show_intro' => '',
            'show_category' => '',
            'link_category' => '',
            'show_parent_category' => '',
            'link_parent_category' => '',
            'show_author' => '',
            'link_author' => '',
            'show_create_date' => '',
            'show_modify_date' => '',
            'show_publish_date' => '',
            'show_item_navigation' => '',
            'show_vote' => '',
            'show_readmore' => '',
            'show_readmore_title' => '',
            'show_icons' => '',
            'show_print_icon' => '',
            'show_email_icon' => '',
            'show_hits' => '',
            'show_noauth' => '',
            'show_feed_link' => '',
            'feed_summary' => '',
            'menu-anchor_title' => '',
            'menu-anchor_css' => '',
            'menu_image' => '',
            'menu_text' => 1,
            'page_title' => '',
            'show_page_heading' => 0,
            'page_heading' => '',
            'pageclass_sfx' => '',
            'menu-meta_description' => '',
            'menu-meta_keywords' => '',
            'robots' => '',
            'secure' => 0,
            'page_title' => ''
        );
    }
}
