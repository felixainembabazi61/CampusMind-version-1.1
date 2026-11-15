-- CampusMind database schema (MySQL / MariaDB)
-- The app creates these tables automatically; this file documents them and can be imported in MySQL Workbench.

CREATE DATABASE IF NOT EXISTS `campusmind` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `campusmind`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(40) PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `role` VARCHAR(20) NOT NULL,
  `passwordHash` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NULL,
  `photo` VARCHAR(255) NULL,
  `specialties` TEXT NULL,
  `bio` TEXT NULL,
  `studentNo` VARCHAR(40) NULL,
  `faculty` VARCHAR(255) NULL,
  `active` TINYINT NOT NULL DEFAULT 1,
  `lastLogin` VARCHAR(32) NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `slots` (
  `id` VARCHAR(40) PRIMARY KEY,
  `counselorId` VARCHAR(40) NOT NULL,
  `date` VARCHAR(10) NOT NULL,
  `start` VARCHAR(5) NOT NULL,
  `end` VARCHAR(5) NOT NULL,
  `mode` VARCHAR(20) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`counselorId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` VARCHAR(40) PRIMARY KEY,
  `slotId` VARCHAR(40) NOT NULL,
  `studentId` VARCHAR(40) NOT NULL,
  `counselorId` VARCHAR(40) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `notes` TEXT NULL,
  `cancelledBy` VARCHAR(40) NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`slotId`) REFERENCES `slots` (`id`),
  FOREIGN KEY (`studentId`) REFERENCES `users` (`id`),
  FOREIGN KEY (`counselorId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `intakes` (
  `id` VARCHAR(40) PRIMARY KEY,
  `studentId` VARCHAR(40) NOT NULL,
  `concerns` TEXT NOT NULL,
  `description` TEXT NOT NULL,
  `mood` INT NOT NULL,
  `duration` VARCHAR(40) NULL,
  `urgency` VARCHAR(20) NOT NULL,
  `safetyRisk` TINYINT NOT NULL DEFAULT 0,
  `contactPref` VARCHAR(40) NULL,
  `preferredCounselorId` VARCHAR(40) NULL,
  `status` VARCHAR(20) NOT NULL,
  `assignedTo` VARCHAR(40) NULL,
  `counselorNote` TEXT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`studentId`) REFERENCES `users` (`id`),
  FOREIGN KEY (`assignedTo`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stories` (
  `id` VARCHAR(40) PRIMARY KEY,
  `authorId` VARCHAR(40) NOT NULL,
  `anonymous` TINYINT NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `tags` TEXT NULL,
  `status` VARCHAR(20) NOT NULL,
  `moderatorNote` TEXT NULL,
  `moderatedBy` VARCHAR(40) NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`authorId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_categories` (
  `id` VARCHAR(40) PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  `color` VARCHAR(10) NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_posts` (
  `id` VARCHAR(40) PRIMARY KEY,
  `categoryId` VARCHAR(40) NOT NULL,
  `authorId` VARCHAR(40) NOT NULL,
  `anonymous` TINYINT NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `pinned` TINYINT NOT NULL DEFAULT 0,
  `locked` TINYINT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`categoryId`) REFERENCES `forum_categories` (`id`),
  FOREIGN KEY (`authorId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_comments` (
  `id` VARCHAR(40) PRIMARY KEY,
  `postId` VARCHAR(40) NOT NULL,
  `authorId` VARCHAR(40) NOT NULL,
  `anonymous` TINYINT NOT NULL DEFAULT 0,
  `body` TEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`postId`) REFERENCES `forum_posts` (`id`),
  FOREIGN KEY (`authorId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reactions` (
  `id` VARCHAR(40) PRIMARY KEY,
  `targetType` VARCHAR(20) NOT NULL,
  `targetId` VARCHAR(40) NOT NULL,
  `userId` VARCHAR(40) NOT NULL,
  `type` VARCHAR(20) NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  UNIQUE (`targetType`, `targetId`, `userId`, `type`),
  FOREIGN KEY (`userId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` VARCHAR(40) PRIMARY KEY,
  `targetType` VARCHAR(20) NOT NULL,
  `targetId` VARCHAR(40) NOT NULL,
  `reporterId` VARCHAR(40) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `details` TEXT NULL,
  `status` VARCHAR(20) NOT NULL,
  `resolvedBy` VARCHAR(40) NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL,
  FOREIGN KEY (`reporterId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` VARCHAR(40) PRIMARY KEY,
  `userA` VARCHAR(40) NOT NULL,
  `userB` VARCHAR(40) NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  FOREIGN KEY (`userA`) REFERENCES `users` (`id`),
  FOREIGN KEY (`userB`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` VARCHAR(40) PRIMARY KEY,
  `conversationId` VARCHAR(40) NOT NULL,
  `senderId` VARCHAR(40) NOT NULL,
  `body` TEXT NOT NULL,
  `readBy` TEXT NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  FOREIGN KEY (`conversationId`) REFERENCES `conversations` (`id`),
  FOREIGN KEY (`senderId`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` VARCHAR(40) PRIMARY KEY,
  `userId` VARCHAR(40) NOT NULL,
  `text` TEXT NOT NULL,
  `link` VARCHAR(255) NULL,
  `isRead` TINYINT NOT NULL DEFAULT 0,
  `createdAt` VARCHAR(32) NOT NULL,
  FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` VARCHAR(60) PRIMARY KEY,
  `value` TEXT NULL,
  `createdAt` VARCHAR(32) NOT NULL,
  `updatedAt` VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` VARCHAR(40) PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `createdAt` VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

