-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 08, 2026 at 02:55 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `appdocs`
--

-- --------------------------------------------------------

--
-- Table structure for table `appsname`
--

CREATE TABLE `appsname` (
  `id` int(11) NOT NULL,
  `app_name` varchar(150) NOT NULL,
  `root_path` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contentcode`
--

CREATE TABLE `contentcode` (
  `id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `summarycode` mediumtext DEFAULT NULL,
  `content` longtext NOT NULL,
  `fs_exists` tinyint(1) NOT NULL DEFAULT 0,
  `fs_mtime` datetime DEFAULT NULL,
  `fs_size` bigint(20) DEFAULT NULL,
  `fs_hash` char(64) DEFAULT NULL,
  `fs_checked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_exports`
--

CREATE TABLE `content_exports` (
  `id` int(11) NOT NULL,
  `file_name` varchar(200) NOT NULL,
  `view_mode` enum('content','summary') NOT NULL DEFAULT 'content',
  `filter_snapshot` longtext DEFAULT NULL,
  `selected_count` int(11) NOT NULL DEFAULT 0,
  `printed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_export_items`
--

CREATE TABLE `content_export_items` (
  `id` int(11) NOT NULL,
  `export_id` int(11) NOT NULL,
  `content_id` int(11) NOT NULL,
  `sort_no` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `moduleapps`
--

CREATE TABLE `moduleapps` (
  `id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `module_name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appsname`
--
ALTER TABLE `appsname`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_app_name` (`app_name`);

--
-- Indexes for table `contentcode`
--
ALTER TABLE `contentcode`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_app_module` (`app_id`,`module_id`),
  ADD KEY `idx_file_path` (`file_path`),
  ADD KEY `fk_content_module` (`module_id`),
  ADD KEY `idx_contentcode_fs_exists` (`fs_exists`);

--
-- Indexes for table `content_exports`
--
ALTER TABLE `content_exports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `content_export_items`
--
ALTER TABLE `content_export_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_export_content` (`export_id`,`content_id`),
  ADD KEY `idx_export_sort` (`export_id`,`sort_no`),
  ADD KEY `fk_export_items_content` (`content_id`);

--
-- Indexes for table `moduleapps`
--
ALTER TABLE `moduleapps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_app_module` (`app_id`,`module_name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appsname`
--
ALTER TABLE `appsname`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contentcode`
--
ALTER TABLE `contentcode`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `content_exports`
--
ALTER TABLE `content_exports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `content_export_items`
--
ALTER TABLE `content_export_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `moduleapps`
--
ALTER TABLE `moduleapps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `contentcode`
--
ALTER TABLE `contentcode`
  ADD CONSTRAINT `fk_content_app` FOREIGN KEY (`app_id`) REFERENCES `appsname` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_content_module` FOREIGN KEY (`module_id`) REFERENCES `moduleapps` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `content_export_items`
--
ALTER TABLE `content_export_items`
  ADD CONSTRAINT `fk_export_items_content` FOREIGN KEY (`content_id`) REFERENCES `contentcode` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_export_items_export` FOREIGN KEY (`export_id`) REFERENCES `content_exports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `moduleapps`
--
ALTER TABLE `moduleapps`
  ADD CONSTRAINT `fk_module_app` FOREIGN KEY (`app_id`) REFERENCES `appsname` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
