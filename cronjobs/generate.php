<?php
if ( !$isQuiet )
{
    $cli->output( "Generating Sitemap...\n" );
}
// Get a reference to eZINI. append.php will be added automatically.
$ini = eZINI::instance( 'site.ini' );
$googlesitemapsINI = eZINI::instance( 'googlesitemaps.ini' );

// Settings variables
if ( $googlesitemapsINI->hasVariable( 'Classes', 'ClassFilterType' )
     AND $googlesitemapsINI->hasVariable( 'Classes', 'ClassFilterArray' )
     AND $ini->hasVariable( 'SiteSettings', 'SiteURL' ) )
{
    $classFilterType = $googlesitemapsINI->variable( 'Classes', 'ClassFilterType' );
    $classFilterArray = $googlesitemapsINI->variable( 'Classes', 'ClassFilterArray' );
}
else
{
    $cli->output( 'Missing INI Variables in configuration block GeneralSettings.' );
    return;
}

//getting custom set site access or default access
$defaultAccess = $ini->variable( 'SiteSettings', 'DefaultAccess' );
if ( $googlesitemapsINI->hasVariable( 'SiteAccessSettings', 'AvailableSiteAccessList' ) )
{
    $siteAccessArray = $googlesitemapsINI->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' );
}
else
{
    $siteAccessArray = array(
        $defaultAccess
    );
}

//fetching all language codes
$languages = array();
$old_access = $GLOBALS['eZCurrentAccess'];
foreach ( $siteAccessArray as $siteAccess )
{
    eZSiteAccess::change( array( 'name' => $siteAccess, 'type' => eZSiteAccess::TYPE_URI ) );

    $specificINI = eZINI::instance( 'site.ini' );
    if ( $specificINI->hasVariable( 'RegionalSettings', 'ContentObjectLocale' ) )
    {
        array_push( $languages, array(
            'siteaccess' => $siteAccess ,
            'locale' => $specificINI->variable( 'RegionalSettings', 'ContentObjectLocale' ) ,
            'siteurl' => $specificINI->variable( 'SiteSettings', 'SiteURL' )
        ) );

    }
    else
    {
        $cli->output( "site.ini[RegionalSettings]ContentObjectLocale not found for siteaccess \"". $siteAccess . "\" \n" );
    }
}

foreach ( $languages as $language )
{
    /* Change the siteaccess */
    $access = eZSiteAccess::change( array(
        "name" => $language["siteaccess"] ,
        "type" => eZSiteAccess::TYPE_URI
    ) );
    unset( $GLOBALS['eZContentObjectDefaultLanguage'] );
    eZContentLanguage::expireCache();
    if ( ! $isQuiet )
    {
        $cli->output( "Generating Sitemap for Siteaccess " . $language["siteaccess"] . " \n" );
    }

    $domain = $language['siteurl'];

    // Get the Sitemap's root node
    $contentINI = eZINI::instance( 'content.ini' );
    $rootNode = eZContentObjectTreeNode::fetch( $contentINI->variable( 'NodeSettings', 'RootNode' ) );

    if ( !$rootNode instanceof eZContentObjectTreeNode )
    {
        $cli->output( "Invalid RootNode for Siteaccess " . $language["siteaccess"] . " \n" );
        continue;
    }

    // Fetch the content tree
    $params = array(
        'MainNodeOnly' => true,
        'ClassFilterType' => $classFilterType,
        'ClassFilterArray' => $classFilterArray,
        'Limit' => 49999, // max. amount of links in 1 sitemap
        'Offset' => 0,
        'SortBy' => array( array( 'depth', true ), array( 'published', true ) )
    );
    $nodeArray = $rootNode->subTree( $params );


    $nodeArrayCount = count( $nodeArray ) + 1;
    if ( $nodeArrayCount == 1 )
    {
        $cli->output( "No Items found under node #". $contentINI->variable( 'NodeSettings', 'RootNode' ). "." );
    }
    if ( !$isQuiet )
    {
        $cli->output( "Adding $nodeArrayCount nodes to the sitemap." );
        $output = new ezcConsoleOutput();
        $bar = new ezcConsoleProgressbar( $output, $nodeArrayCount );
    }

    $addPrio = false;
    if ( $googlesitemapsINI->variable( 'SiteMapSettings', 'AddPriorityToSubtree' ) == 'true' )
    {
        $addPrio = true;
    }

    $sitemap = new xrowGoogleSiteMap();
    // Generate Sitemap
    // Adding the root node
    $object = $rootNode->object();

    $meta = xrowMetaDataFunctions::fetchByObject( $object );

    $modified = $rootNode->attribute( 'modified_subnode' );

    if ( $meta AND $meta->googlemap != '0' )
    {
        $url = $rootNode->attribute( 'url_alias' );
        eZURI::transformURI( $url, true, 'full' );

        $sitemap->add( $url, $modified, $meta->change, $meta->priority );
    }
    elseif ( $meta === false )
    {
        if ( $addPrio )
        {
            $rootDepth = $rootNode->attribute( 'depth' );
            $prio = 1;
        }
        else
        {
            $prio = null;
        }

        $url = $rootNode->attribute( 'url_alias' );
        eZURI::transformURI( $url, true, 'full' );

        $sitemap->add( $url, $modified, null, $prio );
    }

    if ( isset( $bar ) )
    {
        $bar->advance();
    }
    // Adding tree

    foreach ( $nodeArray as $subTreeNode )
    {
        eZContentLanguage::expireCache();
        $object = $subTreeNode->object();
        $meta = xrowMetaDataFunctions::fetchByObject( $object );
        $modified = $subTreeNode->attribute( 'modified_subnode' );

        if ( $meta AND $meta->googlemap != '0' )
        {
            $url = $subTreeNode->attribute( 'url_alias' );
            eZURI::transformURI( $url, true, 'full' );

            $sitemap->add( $url, $modified, $meta->change, $meta->priority );
        }
        elseif ( $meta === false )
        {
            $url = $subTreeNode->attribute( 'url_alias' );
            eZURI::transformURI( $url, true, 'full' );

            if ( $addPrio )
            {
                $rootDepth = $rootNode->attribute( 'depth' );
                $prio = 1 - ( ( $subTreeNode->attribute( 'depth' ) - $rootDepth  ) / 10 );
                if ( $prio <= 0 )
                {
                    $prio = null;
                }
            }
            else
            {
                $prio = null;
            }
            $sitemap->add( $url, $modified, null, $prio );
        }

        if ( isset( $bar ) )
        {
            $bar->advance();
        }
    }

    if ( !$isQuiet )
    {
        $cli->output();
        $cli->output( 'Adding manual items' );
    }

    $manualItems = $googlesitemapsINI->variable( 'SiteMapSettings', 'AddUrlArray' );
    $manualPriority = $googlesitemapsINI->variable( 'SiteMapSettings', 'AddPriorityArray' );
    $manualFrequency = $googlesitemapsINI->variable( 'SiteMapSettings', 'AddFrequencyArray' );
    $itemCount = count( $manualItems );

    if ( !$isQuiet )
    {
        $cli->output( "Found $itemCount entries" );
        $output = new ezcConsoleOutput();
        $bar = new ezcConsoleProgressbar( $output, $itemCount );
    }

    foreach ( $manualItems as $mKey => $mItem )
    {
        $url = $mItem;
        eZURI::transformURI( $url, true, 'full' );

        if ( isset( $manualPriority[$mKey] ) )
        {
            $prio = $manualPriority[$mKey];
        }
        else
        {
            $prio = null;
        }

        if ( isset( $manualFrequency[$mKey] ) )
        {
            $freq = $manualFrequency[$mKey];
        }
        else
        {
            $freq = null;
        }

        $sitemap->add( $url, null, $freq, $prio );
        if ( isset( $bar ) )
        {
            $bar->advance();
        }
    }

    // write XML Sitemap to file
    $dir = eZSys::storageDirectory() . '/sitemap';
    if( !file_exists( $dir ) )
    {
        mkdir( $dir, 0777, true );
    }

    $filename = $dir . '/' . $language['siteaccess'] . '_' . xrowGoogleSiteMap::BASENAME . '.' . xrowGoogleSiteMap::SUFFIX;
    $sitemap->save( $filename );

    if ( function_exists( 'gzencode' )
         AND $googlesitemapsINI->variable( 'SiteMapSettings', 'Gzip' ) == 'enabled' )
    {
        $content = file_get_contents( $filename );
        $content = gzencode( $content );
        file_put_contents( $filename.'.gz', $content );
        unlink( $filename );
        $filename .= '.gz';
    }

    if ( ! $isQuiet )
    {
        $cli->output();
        $cli->output( "Sitemap $filename for siteaccess " . $language['siteaccess'] . " (language code " . $language['locale'] . ") has been generated!\n\n" );
    }
}
eZSiteAccess::change( $old_access );
?>