-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 09-03-2026 a las 10:35:27
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
-- Estructura de tabla para la tabla `FileS3`
--

CREATE TABLE `FileS3` (
  `id_` int NOT NULL,
  `Nombre` varchar(255) NOT NULL,
  `Encriptado` varchar(255) NOT NULL,
  `Tamano` bigint NOT NULL,
  `Metadatos` text,
  `Ruta` varchar(256) NOT NULL,
  `Found` tinyint(1) NOT NULL DEFAULT '0',
  `AccessType` enum('normal','secure') NOT NULL DEFAULT 'normal',
  `PasswordHash` varchar(255) DEFAULT NULL,
  `SecureHint` varchar(255) DEFAULT NULL,
  `SecureUpdatedAt` timestamp NULL DEFAULT NULL,
  `Fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id_` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Volcado de datos para la tabla `FileS3`
--

INSERT INTO `FileS3` (`id_`, `Nombre`, `Encriptado`, `Tamano`, `Metadatos`, `Ruta`, `Found`, `AccessType`, `PasswordHash`, `SecureHint`, `SecureUpdatedAt`, `Fecha`, `user_id_`) VALUES
(1, '20250910_141031_abc733e2.png', 'Data/Chat/GenerationsImages/14/20250910_141031_abc733e2.png', 1897383, '{\"tipo\":\"image\\/png\",\"tamano_kb\":1852.910000000000081854523159563541412353515625,\"hash_sha256\":\"72e2399b56c3191cc540b2ca5fe1c42dd0e85a596e41b33840b371b056e716bc\",\"subido_por\":\"soporte@esforzados.com\",\"ip_origen\":\"189.150.26.210\",\"fecha\":\"2025-09-10\",\"hora\":\"14:23:39\"}', 'Data/Chat/GenerationsImages/14/', 1, 'normal', NULL, NULL, NULL, '2026-02-25 18:19:16', 1),
(2, '20250910_141806_9e982d2e.png', 'Data/Chat/GenerationsImages/14/20250910_141806_9e982d2e.png', 1765799, '', 'Data/Chat/GenerationsImages/14/', 1, 'normal', NULL, NULL, NULL, '2026-02-25 18:19:16', 1),
(3, '20250910_150443_33913280.png', 'Data/Chat/GenerationsImages/14/20250910_150443_33913280.png', 1772781, '', 'Data/Chat/GenerationsImages/14/', 1, 'normal', NULL, NULL, NULL, '2026-02-25 18:19:16', 1);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `FileS3`
--
ALTER TABLE `FileS3`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `Encriptado` (`Encriptado`),
  ADD UNIQUE KEY `uq_files3_user_key` (`user_id_`,`Encriptado`),
  ADD KEY `user_id_` (`user_id_`),
  ADD KEY `idx_FileS3_Ruta` (`Ruta`(191)),
  ADD KEY `idx_FileS3_Found` (`Found`),
  ADD KEY `idx_FileS3_Access` (`AccessType`),
  ADD KEY `idx_FileS3_RutaFoundAccess` (`Ruta`(191),`Found`,`AccessType`),
  ADD KEY `idx_FileS3_UserRuta` (`user_id_`,`Ruta`(191),`Found`),
  ADD KEY `idx_files_user_found` (`user_id_`,`Found`),
  ADD KEY `idx_files_user_ruta` (`user_id_`,`Ruta`(191));

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `FileS3`
--
ALTER TABLE `FileS3`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4826;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
