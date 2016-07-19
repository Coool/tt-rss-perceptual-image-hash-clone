drop table ttrss_plugin_img_phash_urls;

create table ttrss_plugin_img_phash_urls(
	id serial not null,
	article_guid varchar(250) not null,
	url text not null,
	owner_uid integer not null references ttrss_users(id),
	phash bigint);

drop index if exists ttrss_plugin_img_phash_urls_url_idx;
create index ttrss_plugin_img_phash_urls_url_idx on ttrss_plugin_img_phash_urls(url);

CREATE OR REPLACE FUNCTION ttrss_plugin_img_phash_bitcount(i bigint) RETURNS integer AS $$
DECLARE n integer;
				DECLARE amount integer;
BEGIN
	amount := 0;
	FOR n IN 1..64 LOOP
		amount := amount + ((i >> (n-1)) & 1);
	END LOOP;
	RETURN amount;
END
$$ LANGUAGE plpgsql;
