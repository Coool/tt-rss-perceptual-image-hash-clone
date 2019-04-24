This plugin filters duplicate images using perceptual image hashing.

Hashing library: https://github.com/jenssegers/imagehash
Count bits extension for PostgreSQL: https://github.com/sldab/count-bits

Git clone to (tt-rss)/plugins.local/af_zz_img_phash

Note: an alternative to the 'Count bits extension' is to install the SQL function in "sql/bitcount_funcdef_pgsql.sql" and add to config.php:
	define('IMG_HASH_SQL_FUNCTION', true);
	// Alternative to compiling/installing the count-bits extenstion.
