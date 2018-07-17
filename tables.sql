DROP TABLE IF EXISTS `cah_ref`;
DROP TABLE IF EXISTS `cah_card`;
DROP TABLE IF EXISTS `cah_pack`;
DROP TABLE IF EXISTS `cah_player`;

CREATE TABLE `cah_player`(
    `id` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `userId` INTEGER NOT NULL,
    `firstName` VARCHAR(50),
    `chatId` BIGINT NOT NULL,
    `token` VARCHAR(16),
    `score` INTEGER DEFAULT 0,
    `round` INTEGER DEFAULT 0
);

CREATE TABLE `cah_pack`(
    `id` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50),
    `title` VARCHAR(50)
);

CREATE TABLE `cah_card`(
    `id` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `content` TINYTEXT,
    `type` BOOLEAN,
    `pick` INTEGER NOT NULL DEFAULT 1,
    `pack` INTEGER NOT NULL,

    FOREIGN KEY (`pack`) REFERENCES `cah_pack`(`id`) ON DELETE CASCADE
);

CREATE TABLE `cah_ref`(
    `id` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `card` INTEGER NOT NULL,
    `player` INTEGER NOT NULL,
    `pick` INTEGER DEFAULT 0,
    `current` BOOLEAN DEFAULT FALSE,

    FOREIGN KEY (`card`) REFERENCES `cah_card`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`player`) REFERENCES `cah_player`(`id`) ON DELETE CASCADE
);