<?php
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Jenssegers\ImageHash\ImageHash;

class Af_Img_Phash extends Plugin {

	/* @var PluginHost $host */
	private $host;
	private $default_domains_list = "imgur.com reddituploads.com pbs.twimg.com .redd.it i.sli.mg media.tumblr.com redditmedia.com kek.gg gfycat.com";
	private $default_similarity = 5;
	private $data_max_age = 30; // days

	/* @var DiskCache $cache */
	private $cache;

	function about() {
		return array(1.0,
			"Filter duplicate images using perceptual hashing (requires GD)",
			"fox",
			false,
			"https://git.tt-rss.org/fox/ttrss-perceptual-image-hash/wiki");
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/init.css");
	}

	function save() {
		$similarity = (int) $_POST["similarity"];
		$domains_list = $_POST["domains_list"];

		$enable_globally = checkbox_to_sql_bool($_POST["phash_enable_globally"]);

		if ($similarity < 0) $similarity = 0;

		$this->host->set($this, "similarity", $similarity);
		$this->host->set($this, "enable_globally", $enable_globally);
		$this->host->set($this, "domains_list", $domains_list);

		echo $this->T_sprintf("Data saved (%s, %s, %d)", $similarity, $domains_list, $enable_globally);
	}

	function init($host) {
		$this->host = $host;
		$this->cache = new DiskCache("images");

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this, 100);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this, 100);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this, 100);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this, 100);
		$host->add_hook($host::HOOK_ARTICLE_IMAGE, $this, 100);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		?>
		<div dojoType='dijit.layout.AccordionPane'
			title="<i class='material-icons'>photo</i> <?= $this->__( 'Filter similar images (af_img_phash)') ?>">

			<?php
			if (DB_TYPE == "pgsql") {
				if (true === IMG_HASH_SQL_FUNCTION) {
					print_error("Using SQL implementation of bit_count; UI performance may not be as responsive as installing extension 'https://github.com/sldab/count-bits'. See README.txt");
				}
				else {
					try { $res = $this->pdo->query("select 'unique_1bits'::regproc"); } catch (PDOException $e) { ; }
					if (empty($res) || !$res->fetch()) {
						print_error("Required function from count_bits extension not found.");
					}
				}
			}

			$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);
			$domains_list = $this->host->get($this, "domains_list", $this->default_domains_list);
			$enable_globally = $this->host->get($this, "enable_globally");

			?>
			<form dojoType='dijit.form.Form'>

				<?= \Controls\hidden_tag("op", "pluginhandler") ?>
				<?= \Controls\hidden_tag("method", "save") ?>
				<?= \Controls\hidden_tag("plugin", "af_img_phash") ?>

				<script type='dojo/method' event='onSubmit' args='evt'>
					evt.preventDefault();
					if (this.validate()) {
						console.log(dojo.objectToQuery(this.getValues()));
						new Ajax.Request('backend.php', {
							parameters: dojo.objectToQuery(this.getValues()),
							onComplete: function(transport) {
								Notify.info(transport.responseText);
							}
						});
					}
					</script>

				<h2><?= $this->__( "Global settings") ?></h2>

				<fieldset>
					<label><?= $this->__( "Limit to domains (space-separated):") ?>"</label>
					<textarea dojoType='dijit.form.SimpleTextarea' style='height: 100px; width: 500px; display: block'
						required='1' name='domains_list'><?= $domains_list ?></textarea>
				</fieldset>

				<fieldset>
					<label><?= $this->__( "Maximum Hamming distance:") ?></label>
					<input dojoType='dijit.form.NumberSpinner'
						placeholder='5' required='1' name='similarity' id='phash_img_similarity' value='<?= $similarity ?>'>

					<div dojoType='dijit.Tooltip' connectId='phash_img_similarity' position='below'>
					<?= $this->__( "Lower Hamming distance value indicates images being more similar.") ?>
					</div>
				</fieldset>

				<fieldset class='narrow'>
					<label class='checkbox'>
						<?= format_checkbox("phash_enable_globally", $enable_globally) ?>
						<?= $this->__( "Enable for all feeds") ?>
					</label>
				</fieldset>

				<?php print_button("submit", $this->__( "Save"), "class='alt-primary'") ?>

			</form>

			<?php
			$enabled_feeds = $this->filter_unknown_feeds(
				$this->get_stored_array("enabled_feeds"));

			$this->host->set($this, "enabled_feeds", $enabled_feeds);

			if (count($enabled_feeds) > 0) { ?>
				<h3><?= __("Currently enabled for (click to edit):") ?></h3>

				<ul class='panel panel-scrollable list list-unstyled'>
				<?php foreach ($enabled_feeds as $f) { ?>
					<li><i class='material-icons'>rss_feed</i> <a href='#' onclick="CommonDialogs.editFeed(<?= $f ?>)">
						<?= htmlspecialchars(Feeds::_get_title($f)) ?></a></li>
				<?php } ?>
				</ul>
			<?php	} ?>
		</div>
		<?php
	}

	function hook_prefs_edit_feed($feed_id) {
		$enabled_feeds = $this->get_stored_array("enabled_feeds");
		?>
		<header><?= $this->__( "Similar images") ?></header>
		<section>
			<fieldset>
				<label class='checkbox'>
					<?= format_checkbox("phash_similarity_enabled", in_array($feed_id, $enabled_feeds)) ?>
					<?= $this->__( 'Filter similar images') ?></label>
			</fieldset>
		</section>
		<?php
	}

	private function get_stored_array($name) {
		$tmp = $this->host->get($this, $name);

		if (!is_array($tmp)) $tmp = [];

		return $tmp;
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->get_stored_array("enabled_feeds");

		$enable = checkbox_to_sql_bool($_POST["phash_similarity_enabled"] ?? "");
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === false) {
				array_push($enabled_feeds, $feed_id);
			}
		} else {
			if ($key !== false) {
				unset($enabled_feeds[$key]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
	}

	private function rewrite_duplicate($doc, $elem, $api_mode = false) {

		if ($elem->hasAttribute("src")) {
			$uri = validate_url($elem->getAttribute("src"));
			$check_uri = $uri;
		} else if ($elem->hasAttribute("poster")) {
			$check_uri = validate_url($elem->getAttribute("poster"));

			$video_source = $elem->getElementsByTagName("source")->item(0);

			if ($video_source) {
				$uri = $video_source->getAttribute("src");
			}
		}

		if (!empty($check_uri) && !empty($uri)) {

			$p = $doc->createElement('p');

			$a = $doc->createElement("a");
			$a->setAttribute("href", $uri);
			$a->setAttribute("target", "_blank");
			$a->appendChild(new DOMText(truncate_middle($uri, 48, "...")));

			$p->appendChild($a);

			if (!$api_mode) {
				$b = $doc->createElement("a");
				$b->setAttribute("href", "#");
				$b->setAttribute("onclick", "Plugins.Af_Img_Phash.showSimilar(this)");
				$b->setAttribute("data-check-url", validate_url($check_uri));
				$b->appendChild(new DOMText("(similar)"));

				$p->appendChild(new DOMText(" "));
				$p->appendChild($b);
			}

			$elem->parentNode->replaceChild($p, $elem);
		}
	}

	function hook_article_filter($article) {

		$enable_globally = $this->host->get($this, "enable_globally");
		$domains_list = $this->host->get($this, "domains_list");

		if (!$domains_list) $domains_list = $this->default_domains_list;

		$domains_list = explode(" ", $domains_list);

		if (!$enable_globally) {
			if (!in_array($article["feed"]["id"],
					$this->get_stored_array("enabled_feeds"))) {

				return $article;
			}
		}

		$owner_uid = $article["owner_uid"];
		$article_guid = $article["guid_hashed"];

		$doc = new DOMDocument();

		if (!empty($article["content"]) && @$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);

			$imgs = $xpath->query("//img[@src]|//video[@poster]");

			foreach ($imgs as $img) {

				$src = $img->tagName == "video" ? $img->getAttribute("poster") : $img->getAttribute("src");
				$src = validate_url(rewrite_relative_url($article["link"], $src));

				$domain_found = $this->check_src_domain($src, $domains_list);

				if ($domain_found) {

					_debug("phash: checking $src");

					$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_img_phash_urls WHERE
						owner_uid = ? AND url = ? LIMIT 1");
					$sth->execute([$owner_uid, $src]);

					if ($sth->fetch()) {
						_debug("phash: url already stored, not processing");
						continue;
					} else {

						_debug("phash: downloading and calculating hash...");

						$cached_file = sha1($src);
						$cached_file_flag = "$cached_file.phash-flag";

						if ($this->cache->is_writable()) {

							if ($this->cache->exists($cached_file_flag)) {
								_debug("phash: $cached_file_flag exists, looks like we failed on this URL before; skipping.");
								continue;
							}

							$this->cache->touch($cached_file_flag);

							if (!$this->cache->exists($cached_file)) {
								$data = fetch_file_contents(array("url" => $src, "max_size" => MAX_CACHE_FILE_SIZE));

								if ($data) {
									$this->cache->put($cached_file, $data);
								}
							} else {
								_debug("phash: reading from local cache: $cached_file");

								$data = $this->cache->get($cached_file);
							}
						} else {
							_debug("phash: cache directory is not writable");

							$data = fetch_file_contents(array("url" => $src, "max_size" => MAX_CACHE_FILE_SIZE));
						}

						if ($data) {

							$implementation = new PerceptualHash();
							$hasher = new ImageHash($implementation);

							$data_resource = @imagecreatefromstring($data);

							if ($data_resource) {
								$hash = (string)$hasher->hash($data_resource);

								_debug("phash: calculated perceptual hash: $hash");

								// we managed to process this image, it should be safe to remove the flag now
								if ($this->cache->is_writable() && $this->cache->exists($cached_file_flag))
									unlink($this->cache->get_full_path($cached_file_flag));

								if ($hash) {
									$hash = base_convert($hash, 16, 10);

									if (PHP_INT_SIZE > 4) {
										while ($hash > PHP_INT_MAX) {
											$bitstring = base_convert($hash, 10, 2);
											$bitstring = substr($bitstring, 1);

											$hash = base_convert($bitstring, 2, 10);
										}
									}

									$sth = $this->pdo->prepare("INSERT INTO
										ttrss_plugin_img_phash_urls (url, article_guid, owner_uid, phash) VALUES
										(?, ?, ?, ?)");
									$sth->execute([$src, $article_guid, $owner_uid, $hash]);
								}

							} else {
								_debug("phash: unable to load image: $src");
							}

						} else {
							_debug("phash: unable to fetch: $src");
						}

					}
				}
			}

		}

		return $article;
	}

	function api_version() {
		return 2;
	}

	private function filter_unknown_feeds($enabled_feeds) {
		$tmp = array();

		foreach ($enabled_feeds as $feed) {

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);

			if ($row = $sth->fetch()) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
	}

	function hook_render_article($article) {

		return $this->hook_render_article_cdm($article);
	}

	function hook_render_article_api($row) {
		$article = isset($row['headline']) ? $row['headline'] : $row['article'];

		return $this->hook_render_article_cdm($article, true);
	}

	function hook_article_image($enclosures, $content, $site_url) {
		// fake guid because of further checking in hook_render_article_cdm() which we don't need here
		$article = $this->hook_render_article_cdm(["guid" => time(), "content" => $content], false);

		return ["", "", $article["content"]];
	}

	function hook_render_article_cdm($article, $api_mode = false) {

		if (DB_TYPE == "pgsql" && true !== IMG_HASH_SQL_FUNCTION) {
			try { $res = $this->pdo->query("select 'unique_1bits'::regproc"); } catch (PDOException $e) { ; }
			if (empty($res) || !$res->fetch()) return $article;
		}

		$owner_uid = $_SESSION["uid"];

		$doc = new DOMDocument();

		$domains_list = $this->host->get($this, "domains_list", $this->default_domains_list);

		$domains_list = explode(" ", $domains_list);

		$need_saving = false;

		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);

		$article_guid = ($article["guid"] ?? false);

		if (!empty($article_guid) && !empty($article["content"]) && @$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);

			$imgs = $xpath->query("//img[@src]|//video[@poster]");

			foreach ($imgs as $img) {

				$src = $img->tagName == "video" ? $img->getAttribute("poster") : $img->getAttribute("src");
				$src = validate_url(rewrite_relative_url($article["link"] ?? "", $src));

				$domain_found = $this->check_src_domain($src, $domains_list);

				if ($domain_found) {
					// check for URL duplicates first

					$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = ? AND
							url = ? AND
							article_guid != ? LIMIT 1");
					$sth->execute([$owner_uid, $src, $article_guid]);

					if ($sth->fetch()) {
						$need_saving = true;

						$this->rewrite_duplicate($doc, $img, $api_mode);

						continue;
					}

					// check using perceptual hash duplicates

					$sth = $this->pdo->prepare("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
						owner_uid = ? AND
						url = ? LIMIT 1");
					$sth->execute([$owner_uid, $src]);

					if ($row = $sth->fetch()) {
						$phash = $row['phash'];

						//$similarity = 15;

						$sth = $this->pdo->prepare("SELECT article_guid FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = ? AND
							created_at >= ".$this->interval_days($this->data_max_age)." AND
							".$this->bitcount_func($phash)." <= ? ORDER BY created_at LIMIT 1");
						$sth->execute([$owner_uid, $similarity]);

						if ($row = $sth->fetch()) {

							$test_guid = $row['article_guid'];

							if ($test_guid != $article_guid) {
								$need_saving = true;

								$this->rewrite_duplicate($doc, $img, $api_mode);
							}
						}
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveXML();

		return $article;
	}


	function hook_house_keeping() {
		$this->pdo->query("DELETE FROM ttrss_plugin_img_phash_urls
			WHERE created_at < ".$this->interval_days($this->data_max_age));
	}

	private function check_src_domain($src, $domains_list) {
		$src_domain = parse_url($src, PHP_URL_HOST);

		foreach ($domains_list as $domain) {
			if (strstr($src_domain, $domain) !== false) {
				return true;
			}
		}

		return false;
	}

	private function guid_to_article_title($article_guid, $owner_uid) {
		$sth = $this->pdo->prepare("SELECT feed_id, title, updated
			FROM ttrss_entries, ttrss_user_entries
			WHERE ref_id = id AND
				guid = ? AND
				owner_uid = ?");
		$sth->execute([$article_guid, $owner_uid]);

		if ($row = $sth->fetch()) {
			$article_title = htmlspecialchars($row["title"]);
			$feed_id = $row["feed_id"];
			$updated = $row["updated"];

			$article_title = $this->T_sprintf("%s (%s) %s",
				"<span title='$article_guid'>$article_title</span>",
				make_local_datetime($updated, true),
				"<a href='#' onclick='Feeds.open({feed: $feed_id})'><i class='material-icons'>rss_feed</i></a>");

		} else {
			$article_title = "N/A ($article_guid)";
		}

		return $article_title;
	}

	function showsimilar() {
		$url = $_REQUEST["url"];
		$owner_uid = $_SESSION["uid"];
		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);

		?>
		<section class='narrow'>
			<img class='trgm-related-thumb pull-right' src="<?= htmlspecialchars($url) ?>">

			<fieldset>
					<label><?= $this->__("URL:") ?></label>
					<a target='_blank' href="<?= htmlspecialchars($url) ?>">
						<?= truncate_middle(htmlspecialchars($url), 48) ?>
					</a>
			</fieldset>
		<?php

		$sth = $this->pdo->prepare("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
			owner_uid = ? AND
			url = ? LIMIT 1");
		$sth->execute([$owner_uid, $url]);

		if ($row = $sth->fetch()) {

			$phash = $row['phash'];

			$sth = $this->pdo->prepare("SELECT article_guid, ".SUBSTRING_FOR_DATE."(created_at,1,19) AS created_at FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = ? AND
							created_at >= ".$this->interval_days($this->data_max_age)." AND
							".$this->bitcount_func($phash)." <= ? ORDER BY created_at LIMIT 1");
			$sth->execute([$owner_uid, $similarity]);

			if ($row = $sth->fetch()) {

				$article_guid = $row['article_guid'];
				$article_title = $this->guid_to_article_title($article_guid, $owner_uid);
				$created_at = $row['created_at'];

				?>
				<fieldset class='narrow'><label><?= $this->__( "Perceptual hash:") ?></label>
					<?= base_convert($phash, 10, 16) ?>
				</fieldset>
				<fieldset class='narrow'><label><?= $this->__( "Belongs to:") ?></label>
					<?= $article_title ?>
				</fieldset>
				<fieldset class='narrow'><label><?= $this->__( "Registered:") ?></label>
					<?= $created_at ?>
				</fieldset>

				<ul class='panel panel-scrollable list list-unstyled'>
					<?php

					$sth = $this->pdo->prepare("SELECT url, article_guid, ".$this->bitcount_func($phash)." AS distance
						FROM ttrss_plugin_img_phash_urls WHERE
						".$this->bitcount_func($phash)." <= ?
						ORDER BY distance LIMIT 30");
					$sth->execute([$similarity]);

					while ($line = $sth->fetch()) {
						$url = htmlspecialchars($line["url"]);
						$distance = $line["distance"];
						$rel_article_guid = $line["article_guid"];
						$article_title = $this->guid_to_article_title($rel_article_guid, $owner_uid);

						$is_checked = ($rel_article_guid == $article_guid) ? "checked" : "";

						?>
						<li>
							<div>
								<a target='_blank' href="<?= $url ?>"><?= truncate_middle($url, 48) ?></a>

								(<?= $this->T_sprintf("Distance: %d", $distance) ?>)

								<?php if ($is_checked) { ?>
									<strong>(<?= $this->__( "Original") ?>)</strong>
								<?php } ?>

								<div><?= $article_title ?></div>
								<div><img class='trgm-related-thumb' src="<?= $url ?>"></div>
							</div>
						</li>
						<?php
					}
					?>
				</ul>
				<?php

			} else {
				print "<div class='text-error'>" . $this->__( "No information found for this URL.") . "</div>";
			}
		} else {
			print "<div class='text-error'>" . $this->__( "No information found for this URL.") . "</div>";
		}

		?>
		</section>

		<footer class='text-center'>
			<button dojoType='dijit.form.Button' class='alt-primary' type='submit'>
				<?= $this->__( 'Close this window') ?>
			</button>
		</footer>

		<?php
	}

	private function interval_days($days) {
		if (DB_TYPE == "pgsql") {
			return "NOW() - INTERVAL '$days days' ";
		} else {
			return "DATE_SUB(NOW(), INTERVAL $days DAY) ";
		}
	}

	private function bitcount_func($phash) {
		if (DB_TYPE == "pgsql") {
			return true === IMG_HASH_SQL_FUNCTION ? "bit_count('$phash' # phash)" : "unique_1bits('$phash', phash)";
		} else {
			return "bit_count('$phash' ^ phash)";
		}
	}
}