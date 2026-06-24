CREATE TABLE IF NOT EXISTS `gls_pakket_status` (
    `parcel_no`     VARCHAR(50)     NOT NULL,
    `state`         VARCHAR(50)     NOT NULL DEFAULT '',
    `beschrijving`  VARCHAR(255)    NOT NULL DEFAULT '',
    `datum_event`   DATETIME        NULL,
    `raw_json`      MEDIUMTEXT      NOT NULL,
    `bijgewerkt`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`parcel_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
