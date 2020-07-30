## This plugin filters duplicate images using perceptual image hashing

* Hashing library: https://github.com/jenssegers/imagehash
* Count bits extension for PostgreSQL: https://github.com/sldab/count-bits

### Installation

- Git clone to ``plugins.local/af_img_phash``
- Install plugin schema into tt-rss database (``sql/phash_schema_pgsql.sql`` or ``sql/phash_schema_mysql.sql``)
- Enable in feed editor for specific feeds (after enabling the plugin)

### If you can't use count bits on PostgreSQL, you can do the following:

Note that using this SQL hashing function would be several orders of magnitude slower than count bits which will affect overal tt-rss performance.

- Install SQL hashing function in `sql/bitcount_funcdef_pgsql.sql`
- Add the following option to `config.php`:

```
	define('IMG_HASH_SQL_FUNCTION', true);
```

