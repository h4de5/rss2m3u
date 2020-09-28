<?php

// clear old files in podcasts folder:
// find . -type f -mtime +45 -not -path "*eaDir*" -exec rm -f {} \;

class Rss2Playlist {
	/**
	 * @var array
	 */
	protected $config = [];

	/**
	 * @var array
	 */
	protected $latest = [];

	/**
	 * @param $configFile
	 */
	public function __construct($configFile = "../feeds.conf") {
		$this->config = $this->init($configFile);
	}

	/**
	 * @param string $configFile default path to config file
	 * @return array
	 */
	protected function init($configFile = "../feeds.conf") {
		if (!file_exists($configFile)) {
			// throw new ErrorException("Please make sure that a feeds.conf is available in ". realpath(".."));
			trigger_error("Please make sure that a " . basename($configFile) . " is available in " . dirname(realpath($configFile)) . '/', E_USER_ERROR);
		}

		$config = parse_ini_file($configFile, true, INI_SCANNER_TYPED);

		if (empty($config)) {
			trigger_error("The given config file " . realpath($configFile) . " seems to be empty or in an invalid format.", E_USER_ERROR);
		}

		if (empty($config["feeds"])) {
			trigger_error("There are no podcast urls defined in the [feeds] section of the given config file.", E_USER_ERROR);
		}

		// make sure path ends with /
		$config['base']['playlist_base_path'] = rtrim($config['base']['playlist_base_path'], '/') . '/';
		$config['base']['download_base_path'] = rtrim($config['base']['download_base_path'], '/') . '/';
		$config['base']['download_rel_path'] = rtrim($config['base']['download_rel_path'], '/') . '/';

		if (!is_writable($config['base']['playlist_base_path'])) {
			trigger_error("Target directory for playlists " . realpath($config['base']['playlist_base_path']) . " is not writeable.", E_USER_ERROR);
		}
		if (!is_writable($config['base']['download_base_path'])) {
			trigger_error("Target directory for downloads " . realpath($config['base']['download_base_path']) . " is not writeable.", E_USER_ERROR);
		}

		if (empty($config['base']['size_to_seconds_factor']) || intval($config['base']['size_to_seconds_factor']) <= 0) {
			$config['base']['size_to_seconds_factor'] = 16000;
		}

		return $config;
	}

	/**
	 * @param $data
	 */
	public function toM3u($data) {

		// TODO - only show last entries..

		// #EXTINF:3403,c't uplink 28.5: Wer braucht noch Digitalkameras?
		// https://cdnapisec.kaltura.com/p/2238431/sp/0/playManifest/entryId/0_pa2oz6um/format/url/protocol/https/flavorParamId/1608871/c-t-uplink-28-5-Wer-braucht-noch-Digitalkameras.mp3

		$formatted = '#EXTM3U' . PHP_EOL;
		if (!empty($data)) {
			foreach ($data as $idx => $entry) {
				$formatted .= '#EXTINF:' . $entry['length'] . ',' . $entry['title'] . PHP_EOL;
				$formatted .= $entry['path'] . PHP_EOL;
			}
		}

		return $formatted;
	}

	/**
	 * @param $data
	 */
	public function toPls($data) {

		// [playlist]
		// File1=Alternative\everclear - SMFTA.mp3
		// Title1=Everclear - So Much For The Afterglow
		// Length1=233

		// NumberOfEntries=1
		// Version=2

		$formatted = '[playlist]' . PHP_EOL;

		if (!empty($data)) {
			foreach ($data as $idx => $entry) {
				$formatted .= sprintf('File%d=%s' . PHP_EOL, $entry['idx'], $entry['path']);
				$formatted .= sprintf('Title%d=%s' . PHP_EOL, $entry['idx'], $entry['title']);
				$formatted .= sprintf('Length%d=%s' . PHP_EOL, $entry['idx'], $entry['length']);
			}
		}

		$formatted .= 'NumberOfEntries=' . count($data) . PHP_EOL;
		$formatted .= 'Version=2' . PHP_EOL;

		return $formatted;
	}

	/**
	 * @param $kbytes
	 * @param array $feedconf
	 * @return array
	 */
	public function rss2data($feedconf = []) {
		try {
			$list = [];
			$url = $feedconf['url'];
			$kbytes = $feedconf['khz'];

			$rss = simplexml_load_file($url);
			$artist = trim($rss->channel->title);

			$idx = 0;
			$top = !empty($feedconf['top']) ? intval($feedconf['top']) : 0;
			$pubDate = '';
			// $last = !empty($feedconf['last']) ? intval($feedconf['last']) : 0;

			foreach ($rss->channel->item as $item) {
				// only show top entries from feed
				if (!empty($top) && $idx >= $top) {
					continue;
				}

				if (isset($item->enclosure)) {
					$title = $item->title;
					$path = $item->enclosure['url'];
					$length = round($item->enclosure['length'] / $kbytes);
				} else {
					// <media:content url="http://feedproxy.google.com/~r/rf/KsMp/~5/TbT_XdtNE5k/2019-07-21_Sommerbrief_1.mp3" fileSize="4311143" type="audio/x-mpeg" />
					$title = $item->title;
					$path = $item->children('media', true)->content['url'];
					$length = round($item->children('media', true)->content['fileSize'] / $kbytes);
				}
				// if length could not be parsed, check for itunes duration
				if (empty($length)) {
					$length = round($item->children('itunes', true)->duration);
				}
				// if no dash is given, prepend podcast title
				if (strpos($title, '-') === false && stripos($title, $artist) === false) {
					$title = $artist . ' - ' . $title;
				}

				$pubDate = strtotime($item->pubDate);
				$playlistPath = $path;

				$file_saved = false;
				if (!empty($feedconf['download'])) {

					$targetSubdir = preg_replace('/[^A-Za-z0-9-_öäüÖÄÜß]/', '', str_replace('.m3u', '', $feedconf['name'])) . '/';
					$targetDir = $this->config['base']['download_base_path'] . $targetSubdir;

					if (!is_dir($targetDir)) {
						mkdir($targetDir);
					}

					$web_path = parse_url($path, PHP_URL_PATH);
					$web_file = basename($web_path);

					// fallback for name
					if (empty($web_file)) {
						$web_file = $feedconf['name'] . date('Y-m-d') . '.mp3';
					}
					$extension = strtolower(substr($web_file, -4));
					if (!in_array($extension, ['.mp3', '.aac', '.m4a', '.ogg'])) {
						$web_file .= '.mp3';
					}
					$targetFile = $targetDir . $web_file;

					if (!file_exists($targetFile)) {
						set_time_limit(60);
						// $file_saved = file_put_contents($targetFile, file_get_contents($path));
						$fp = @fopen($path, 'r');
						if (false !== $fp) {
							$file_saved = file_put_contents($targetFile, $fp);
						}

						if (!$file_saved) {
							// trigger_error("Could not download " . $path . " to " . realpath($targetFile), E_USER_ERROR);
						} else {
							if (!empty($this->config['base']['post_download_cmd'])) {
								$cmd = str_replace('$file', escapeshellarg(realpath($targetFile)), $this->config['base']['post_download_cmd']);
								// echo $cmd . PHP_EOL;
								$output = [];
								$cmd_result = false;
								exec($cmd, $output, $cmd_result);
								if (0 !== $cmd_result) {
									trigger_error("Could not execute post commmand " . htmlentities($cmd), E_USER_ERROR);
								}
							}
						}
					} else {
						// file already there
						$file_saved = true;
					}

					if ($file_saved) {
						// if rel path is set, rebuild the path
						if (!empty(trim($this->config['base']['download_rel_path'], '/\\'))) {
							$targetFile = $this->config['base']['download_rel_path'] . $targetSubdir . $web_file;
						}
						$playlistPath = $targetFile;
					}
				}

				// if file was not saved - use url
				if (!$file_saved) {
					// some player do not work with https links
					if (!empty($feedconf['scheme']) && 'http' === $feedconf['scheme']) {
						$path = str_replace('https://', 'http://', $path);
					} else if (!empty($feedconf['scheme']) && 'https' === $feedconf['scheme']) {
						$path = str_replace('http://', 'https://', $path);
					}
				}

				$idx++;
				$list[] = $listitem = [
					'title' => $title,
					'path' => $playlistPath,
					'length' => $length,
					'pubDate' => $pubDate,
					'idx' => $idx
				];

				// check for latest settings
				if (!empty($feedconf['_latest'])) {
					// echo 'adding title: ' . $title . ' to latest: ' . $feedconf['_latest'] . PHP_EOL;
					$this->latest[$feedconf['_latest']][] = $listitem;
				}
			}
			return $list;
		} catch (Exception $e) {
			return '';
		}
	}

	public function readFeeds() {

		header('Content-Type:text/plain');

		foreach ($this->config['feeds'] as $filename => $feed) {

			if (file_exists($this->config['base']['playlist_base_path'] . $filename) && !is_writable($this->config['base']['playlist_base_path'] . $filename)) {
				trigger_error("Target playlist file " . realpath($this->config['base']['playlist_base_path'] . $filename) . " is not writeable.", E_USER_WARNING);
				continue;
			}

			if (strtolower(substr($filename, -4)) === '.m3u') {
				$feed['type'] = 'm3u';
				$feed['name'] = substr($filename, 0, -4);
			} else if (strtolower(substr($filename, -4)) === '.pls') {
				$feed['type'] = 'pls';
				$feed['name'] = substr($filename, 0, -4);
			} else {
				trigger_error("Unknown playlist format " . realpath($this->config['base']['playlist_base_path'] . $filename) . ".", E_USER_WARNING);
			}
			if (empty($feed['khz'])) {
				$feed['khz'] = intval($this->config['base']['size_to_seconds_factor']);
			}

			// get m3u playlist from url
			if (!empty($feed['url']) && '_latest' !== $feed['url']) {
				$playlistData = $this->rss2data($feed);
			} else if (!empty($this->latest[$filename])) {
				$playlistData = $this->latest[$filename];
				uasort($playlistData, ['self', 'sortByPubDate']);
				if (!empty($feed['top']) && intval($feed['top']) > 0) {
					$playlistData = array_slice($playlistData, 0, intval($feed['top']));
				}
			} else {
				// no url, and no items for latest
				trigger_error("Playlist url for " . \htmlentities($filename) . " was empty or latest config was invalid.", E_USER_WARNING);
				continue;
			}

			if ('m3u' == $feed['type']) {
				$playlistContent = $this->toM3u($playlistData);
			} else if ('pls' == $feed['type']) {
				$playlistContent = $this->toPls($playlistData);
			}

			// output name of playlist file + newest empisode
			echo $this->config['base']['playlist_base_path'] . $filename . PHP_EOL;
			list($first, $second, $rest) = explode("\n", $playlistContent, 3);
			echo $second . PHP_EOL . PHP_EOL;

			// save it to disk
			if (!file_put_contents($this->config['base']['playlist_base_path'] . $filename, $playlistContent, LOCK_EX)) {
				trigger_error("Could not write to target playlist file " . realpath($this->config['base']['playlist_base_path'] . $filename) . ".", E_USER_WARNING);
				continue;
			}
		}
	}

	/**
	 * sorts by datetime - newsest on top
	 * @param datetime $a
	 * @param datetime $b
	 */
	protected function sortByPubDate($a, $b) {
		return ($b['pubDate'] <=> $a['pubDate']);
	}

	// public function renderResult() {
	// 	header('Content-Type:text/plain');
	// }

}

call_user_func(
	function () {
		$rss2Playlist = new Rss2Playlist('../feeds.conf');

		$rss2Playlist->readFeeds();

	}
);
