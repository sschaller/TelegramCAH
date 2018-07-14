DROP TABLE `cah_ref`;
DROP TABLE `cah_card`;
DROP TABLE `cah_pack`;
DROP TABLE `cah_user`;
DROP TABLE `cah_chat`;

CREATE TABLE `cah_chat`(
    `id` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(50)
);

CREATE TABLE `cah_user`(
    `id` INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `firstName` VARCHAR(50),
    `chat` INTEGER NOT NULL,
    `token` VARCHAR(50),
    `turn` BOOLEAN,
    FOREIGN KEY (`chat`) REFERENCES `cah_chat`(`id`) ON DELETE CASCADE
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
    `user` INTEGER NOT NULL,
    `chat` INTEGER NOT NULL,
    `used` BOOLEAN,

    FOREIGN KEY (`card`) REFERENCES `cah_card`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user`) REFERENCES `cah_user`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`chat`) REFERENCES `cah_chat`(`id`) ON DELETE CASCADE
);