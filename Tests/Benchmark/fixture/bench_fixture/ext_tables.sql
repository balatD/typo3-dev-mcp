CREATE TABLE tt_content (
	tx_benchfixture_teaser varchar(255) DEFAULT '' NOT NULL,
	tx_benchfixture_level int(11) DEFAULT '0' NOT NULL,
	tx_benchfixture_related int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tx_benchfixture_item (
	title varchar(255) DEFAULT '' NOT NULL,
	subtitle varchar(255) DEFAULT '' NOT NULL,
	weight int(11) DEFAULT '0' NOT NULL,
	is_featured smallint(5) unsigned DEFAULT '0' NOT NULL
);
