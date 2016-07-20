drop table ttrss_plugin_img_phash_urls;

create table ttrss_plugin_img_phash_urls(
	id serial not null,
	article_guid varchar(250) not null,
	url text not null,
	owner_uid integer not null references ttrss_users(id),
	phash bigint,
	created_at timestamp not null default NOW());

drop index if exists ttrss_plugin_img_phash_urls_url_idx;
create index ttrss_plugin_img_phash_urls_url_idx on ttrss_plugin_img_phash_urls(url);

drop index if exists ttrss_plugin_img_phash_urls_created_idx;
create index ttrss_plugin_img_phash_urls_created_idx on ttrss_plugin_img_phash_urls (created_at);
