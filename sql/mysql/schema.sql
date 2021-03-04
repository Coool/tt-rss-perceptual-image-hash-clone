create table if not exists ttrss_plugin_img_phash_urls(
  id integer not null PRIMARY KEY auto_increment,
  article_guid varchar(250) not null,
  url text not null,
  owner_uid integer not null references ttrss_users(id) on delete CASCADE,
  phash bigint,
  created_at timestamp not null default NOW()) ENGINE=InnoDB;

create index ttrss_plugin_img_phash_urls_created_idx on ttrss_plugin_img_phash_urls (created_at);
