## This plugin filters duplicate images using perceptual image hashing

* Hashing library: https://github.com/jenssegers/imagehash
* Count bits extension for PostgreSQL: https://github.com/sldab/count-bits

You can use this database image with stock tt-rss docker setup: https://hub.docker.com/repository/docker/cthulhoo/postgres-count-bits

(docker-compose.override.yml):

```yml
services:
  db:
    image: cthulhoo/postgres-count-bits:13-latest
```

### Installation

- Git clone to ``plugins.local/af_img_phash``
- Enable in feed editor for specific feeds (after enabling the plugin)

### If you can't use `count bits` on PostgreSQL, you can do the following:

Note that using this SQL hashing function would be several orders of magnitude slower than count bits which will affect overal tt-rss performance.

- Install SQL hashing function in `sql/bitcount_funcdef_pgsql.sql`
- Set config option: TTRSS_IMG_HASH_SQL_FUNCTION (either via environment or through `config.php`)

