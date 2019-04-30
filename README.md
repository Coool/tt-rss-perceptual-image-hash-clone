# This plugin filters duplicate images using perceptual image hashing.

* Hashing library: https://github.com/jenssegers/imagehash
* Count bits extension for PostgreSQL: https://github.com/sldab/count-bits

## Installation

1. Git clone to ``plugins.local/af_zz_img_phash``
2. Enable in feed editor for specific feeds (after enabling the plugin)

## If you can't use count bits on PostgreSQL, you can do the following:

1. Install the SQL function in "sql/bitcount_funcdef_pgsql.sql"
2. Add the following option to `config.php`:

```
	define('IMG_HASH_SQL_FUNCTION', true);
```

Note that using native SQL hashing function would be several orders of magnitude
slower than count bits which may affect overal tt-rss performance.
