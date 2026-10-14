#
# Per-site configuration (key/value, JSON encoded; secrets encrypted)
#
CREATE TABLE tx_derspeamazonlinkmonitor_setting (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	name varchar(100) DEFAULT '' NOT NULL,
	value mediumtext,

	PRIMARY KEY (uid),
	UNIQUE KEY site_name (site, name)
);

#
# Crawled pages that contain Amazon links
#
CREATE TABLE tx_derspeamazonlinkmonitor_page (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	url_hash varchar(40) DEFAULT '' NOT NULL,
	url text,
	title text,
	last_crawled_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY site_url (site, url_hash)
);

#
# Amazon links per page with their latest check result
#
CREATE TABLE tx_derspeamazonlinkmonitor_link (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	page int(11) unsigned DEFAULT '0' NOT NULL,
	link_hash varchar(40) DEFAULT '' NOT NULL,
	link_url text,
	link_text text,
	context text,
	asin varchar(20) DEFAULT '' NOT NULL,
	affiliate_id varchar(100) DEFAULT '' NOT NULL,
	occurrence_count int(11) DEFAULT '1' NOT NULL,
	status varchar(20) DEFAULT '' NOT NULL,
	status_code int(11) DEFAULT NULL,
	status_reason varchar(255) DEFAULT '' NOT NULL,
	check_method varchar(20) DEFAULT '' NOT NULL,
	product_title text,
	availability varchar(50) DEFAULT '' NOT NULL,
	last_checked_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY page_link (page, link_hash),
	KEY site_link (site, link_hash),
	KEY site_status (site, status)
);

#
# Crawl runs (totals for the history chart)
#
CREATE TABLE tx_derspeamazonlinkmonitor_run (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	started_at int(11) unsigned DEFAULT '0' NOT NULL,
	finished_at int(11) unsigned DEFAULT '0' NOT NULL,
	total int(11) DEFAULT '0' NOT NULL,
	ok int(11) DEFAULT '0' NOT NULL,
	broken int(11) DEFAULT '0' NOT NULL,
	warning int(11) DEFAULT '0' NOT NULL,
	unknown int(11) DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_finished (site, finished_at)
);

#
# Status changes of links (added, broken, ok, removed, ...)
#
CREATE TABLE tx_derspeamazonlinkmonitor_history (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	run int(11) unsigned DEFAULT '0' NOT NULL,
	link_url text,
	page_url text,
	event varchar(20) DEFAULT '' NOT NULL,
	status varchar(20) DEFAULT '' NOT NULL,
	status_code int(11) DEFAULT NULL,
	created_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_created (site, created_at)
);

#
# Crawl jobs, processed in time-boxed steps
#
CREATE TABLE tx_derspeamazonlinkmonitor_job (
	uid int(11) unsigned NOT NULL auto_increment,
	site varchar(255) DEFAULT '' NOT NULL,
	type varchar(30) DEFAULT '' NOT NULL,
	status varchar(20) DEFAULT '' NOT NULL,
	state mediumtext,
	progress text,
	total int(11) DEFAULT '0' NOT NULL,
	done int(11) DEFAULT '0' NOT NULL,
	error text,
	created_at int(11) unsigned DEFAULT '0' NOT NULL,
	updated_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_status (site, status)
);
