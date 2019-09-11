<?php

function init() {
  // default path to config file
  $config_file = "../feeds.conf";

  if(!file_exists($config_file)) {
    // throw new ErrorException("Please make sure that a feeds.conf is available in ". realpath(".."));
    trigger_error("Please make sure that a ".basename($config_file)." is available in ". dirname(realpath($config_file)). '/', E_USER_ERROR);
  }

  $config = parse_ini_file($config_file, true, INI_SCANNER_TYPED);

  if(empty($config)) {
    trigger_error("The given config file ".realpath($config_file)." seems to be empty or in an invalid format.", E_USER_ERROR);
  }

  if(empty($config["feeds"])) {
    trigger_error("There are no podcast urls defined in the [feeds] section of the given config file.", E_USER_ERROR);
  }

  if(!is_writable($config['base']['playlist_base_path'])) {
    trigger_error("Target directory for playlists ".realpath($config['base']['playlist_base_path'])." is not writeable.", E_USER_ERROR);
  }

  if(empty($config['base']['size_to_seconds_factor']) || intval($config['base']['size_to_seconds_factor']) <= 0) {
    $config['base']['size_to_seconds_factor'] = 16000;
  }

  // make sure path ends with /
  $config['base']['playlist_base_path'] = rtrim($config['base']['playlist_base_path'], '/').'/';

  return $config;
}

function rss2m3u($url, $kbytes = 16000, $feedconf = array()) {
  global $config;

  try {
    $rss = simplexml_load_file($url);
    $m3u_text = '#EXTM3U'. PHP_EOL;
    $artist = trim($rss->channel->title);

    $idx = 0;
    $top = !empty($feedconf['top']) ? intval($feedconf['top']) : 0;
    $last = !empty($feedconf['last']) ? intval($feedconf['last']) : 0;

    foreach($rss->channel->item as $item) {
        // only show top entries
        if (!empty($top) && $idx >= $top) {
            continue;
        }
        // TODO - only show last entries..

        // #EXTINF:3403,c't uplink 28.5: Wer braucht noch Digitalkameras?
        // https://cdnapisec.kaltura.com/p/2238431/sp/0/playManifest/entryId/0_pa2oz6um/format/url/protocol/https/flavorParamId/1608871/c-t-uplink-28-5-Wer-braucht-noch-Digitalkameras.mp3

        if(isset($item->enclosure)) {
        $title = $item->title;
        $path = $item->enclosure['url'];
        $length = round($item->enclosure['length']/$kbytes);
        } else {
        // <media:content url="http://feedproxy.google.com/~r/rf/KsMp/~5/TbT_XdtNE5k/2019-07-21_Sommerbrief_1.mp3" fileSize="4311143" type="audio/x-mpeg" />
        $title = $item->title;
        $path = $item->children('media', true)->content['url'];
        $length =  round($item->children('media', true)->content['fileSize']/$kbytes);
        }
        // if length could not be parsed, check for itunes duration
        if(empty($length)) {
        $length =  round($item->children('itunes', true)->duration);
        }
        // if no dash is given, prepend podcast title
        if(strpos($title, '-') === false && stripos($title, $artist) === false) {
        $title = $artist .' - '. $title;
        }

        // some player do not work with https links
        if( !empty($feedconf['scheme']) && $feedconf['scheme'] === 'http' ) {
            $path = str_replace('https://', 'http://', $path);
        } else if( !empty($feedconf['scheme']) && $feedconf['scheme'] === 'https' ) {
            $path = str_replace('http://', 'https://', $path);
        }

        $m3u_text .= '#EXTINF:'. $length .','. $title .PHP_EOL;
        $m3u_text .= $path . PHP_EOL;

        $idx ++;
    }
    return $m3u_text;
  }
  catch(Exception $e) {
    return '';
  }
}

$config = init();

header('Content-Type:text/plain');

foreach($config['feeds'] as $filename => $feed) {

  $url = $feed['url'];

  if(file_exists($config['base']['playlist_base_path']. $filename) && !is_writable($config['base']['playlist_base_path']. $filename)) {
    trigger_error("Target playlist file ".realpath($config['base']['playlist_base_path'].$filename)." is not writeable.", E_USER_WARNING);
    continue;
  }

  $khz = 0;
  if(!empty($feed['url']['khz'])) {
    $khz = intval($feed['url']['khz']);
  }
  if(empty($khz)) {
    $khz = intval($config['base']['size_to_seconds_factor']);
  }

  // get m3u playlist from url
  $m3u_text = rss2m3u($url, $khz, $feed);

  // output name of playlist file + newest empisode
  echo $config['base']['playlist_base_path'].$filename . PHP_EOL;
  list($first, $second, $rest) = explode("\n", $m3u_text, 3);
  echo $second. PHP_EOL. PHP_EOL;

  // save it to disk
  if(!file_put_contents($config['base']['playlist_base_path']. $filename, $m3u_text, LOCK_EX)) {
    trigger_error("Could not write to target playlist file ".realpath($config['base']['playlist_base_path'].$filename).".", E_USER_WARNING);
    continue;
  }
}