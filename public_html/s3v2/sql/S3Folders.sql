-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 09-03-2026 a las 10:35:59
-- Versión del servidor: 8.0.45-36
-- Versión de PHP: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `adbbmis1_edu`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `S3Folders`
--

CREATE TABLE `S3Folders` (
  `id_` bigint UNSIGNED NOT NULL,
  `user_id_` int NOT NULL DEFAULT '0',
  `Prefix` varchar(1024) NOT NULL,
  `Nombre` varchar(255) NOT NULL,
  `ParentPrefix` varchar(1024) DEFAULT NULL,
  `Found` tinyint(1) NOT NULL DEFAULT '0',
  `AccessType` enum('normal','secure') NOT NULL DEFAULT 'normal',
  `PasswordHash` varchar(255) DEFAULT NULL,
  `SecureHint` varchar(255) DEFAULT NULL,
  `SecureUpdatedAt` timestamp NULL DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `PrefixHash` binary(32) GENERATED ALWAYS AS (unhex(sha2(`Prefix`,256))) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Volcado de datos para la tabla `S3Folders`
--

INSERT INTO `S3Folders` (`id_`, `user_id_`, `Prefix`, `Nombre`, `ParentPrefix`, `Found`, `AccessType`, `PasswordHash`, `SecureHint`, `SecureUpdatedAt`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 1, 'Data/', 'Data', NULL, 1, 'normal', NULL, NULL, NULL, '2026-02-25 18:19:19', '2026-03-06 18:24:41'),
(2, 1, 'Data/Backup/', 'Backup', 'Data/', 1, 'normal', NULL, NULL, NULL, '2026-02-25 18:19:19', '2026-03-06 18:24:41'),
(3, 1, 'Data/Chat/', 'Chat', 'Data/', 1, 'normal', NULL, NULL, NULL, '2026-02-25 18:19:19', '2026-03-06 18:24:41');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `S3Folders`
--
ALTER TABLE `S3Folders`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uniq_user_prefix` (`user_id_`,`Prefix`(191)),
  ADD UNIQUE KEY `uq_s3folders_user_prefixhash` (`user_id_`,`PrefixHash`),
  ADD KEY `idx_user_parent` (`user_id_`,`ParentPrefix`(191)),
  ADD KEY `idx_user_found` (`user_id_`,`Found`),
  ADD KEY `idx_user_access` (`user_id_`,`AccessType`),
  ADD KEY `idx_user_parent_found_access` (`user_id_`,`ParentPrefix`(191),`Found`,`AccessType`),
  ADD KEY `idx_folders_user_found` (`user_id_`,`Found`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `S3Folders`
--
ALTER TABLE `S3Folders`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=190;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
