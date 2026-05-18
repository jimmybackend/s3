-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 18-05-2026 a las 11:16:20
-- Versión del servidor: 8.0.45-36
-- Versión de PHP: 8.3.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `adbbmis1_Cloud`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `AccessControl`
--

CREATE TABLE `AccessControl` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `date_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `action` enum('Inicio de Sesión','Cierre de Sesión','Cambio de Contraseña','Otro') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `action_details` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calls`
--

CREATE TABLE `calls` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id_` int NOT NULL,
  `from_user_id` int DEFAULT NULL,
  `from` varchar(191) DEFAULT NULL,
  `to` varchar(191) DEFAULT NULL,
  `status` enum('ringing','accepted','rejected','ended') NOT NULL DEFAULT 'ringing',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ChatMessages`
--

CREATE TABLE `ChatMessages` (
  `id_` int NOT NULL,
  `session_id_` int NOT NULL,
  `user_id_` int NOT NULL,
  `role` enum('system','user','assistant','tool') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `content_type` enum('text','image','video','audio','file') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'text',
  `content` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `s3_key` varchar(1024) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `mime_type` varchar(128) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `size_bytes` bigint DEFAULT NULL,
  `thumb_s3_key` varchar(1024) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `duration_ms` int DEFAULT NULL,
  `model_id` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `stop_reason` varchar(32) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `prompt_tokens` int DEFAULT NULL,
  `completion_tokens` int DEFAULT NULL,
  `latency_ms` int DEFAULT NULL,
  `meta` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ChatSessions`
--

CREATE TABLE `ChatSessions` (
  `id_` int NOT NULL,
  `user_id_` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `model_id` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `provider` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `status` enum('open','archived','closed') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'open',
  `meta` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

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
  `AccessType` enum('normal','secure','unlocked') NOT NULL DEFAULT 'normal',
  `PasswordHash` varchar(255) DEFAULT NULL,
  `SecureHint` varchar(255) DEFAULT NULL,
  `SecureUpdatedAt` timestamp NULL DEFAULT NULL,
  `Fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id_` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `FileS3_RepairControl`
--

CREATE TABLE `FileS3_RepairControl` (
  `id_` int NOT NULL,
  `Prefix` varchar(512) NOT NULL,
  `Estado` enum('pendiente','procesando','revisado','error') NOT NULL DEFAULT 'pendiente',
  `UltimoMensaje` text,
  `TotalS3` int NOT NULL DEFAULT '0',
  `TotalBD` int NOT NULL DEFAULT '0',
  `TotalAcciones` int NOT NULL DEFAULT '0',
  `LastProcessedAt` timestamp NULL DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `FileS3_RepairLog`
--

CREATE TABLE `FileS3_RepairLog` (
  `id_` bigint NOT NULL,
  `Prefix` varchar(512) NOT NULL,
  `FileS3_id` int DEFAULT NULL,
  `Accion` enum('keep','update_encriptado','delete_duplicate','missing_in_s3','warning') NOT NULL,
  `Nombre` varchar(255) DEFAULT NULL,
  `EncriptadoAntes` varchar(255) DEFAULT NULL,
  `EncriptadoDespues` varchar(255) DEFAULT NULL,
  `S3Key` varchar(1024) DEFAULT NULL,
  `Detalle` text,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `UserAIInteractions`
--

CREATE TABLE `UserAIInteractions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `question` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `answer` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `interaction_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ai_model_used` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `feedback` enum('Positivo','Negativo','Neutral') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `Users`
--

CREATE TABLE `Users` (
  `id` int NOT NULL,
  `firstname` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `lastname` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `curp` varchar(18) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `gender` enum('Masculino','Femenino','Otro') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `birthdate` date DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `neighborhood` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `postalcode` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `state` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `country` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `homephone` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `mobilephone` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `role` enum('Alumno','Docente','Administración','Finanzas','Recursos Humanos','Ventas','Marketing','Soporte','Servicio Social','Otros') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `registrationdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `profilepicture` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `chat` tinyint NOT NULL,
  `userstatus` enum('Activo','Inactivo') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `AccessControl`
--
ALTER TABLE `AccessControl`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_status_created` (`user_id_`,`status`,`created_at`),
  ADD KEY `idx_status_created` (`status`,`created_at`),
  ADD KEY `fk_calls_user_caller` (`from_user_id`);

--
-- Indices de la tabla `ChatMessages`
--
ALTER TABLE `ChatMessages`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_msgs_session` (`session_id_`),
  ADD KEY `idx_msgs_user` (`user_id_`);

--
-- Indices de la tabla `ChatSessions`
--
ALTER TABLE `ChatSessions`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_chats_user` (`user_id_`),
  ADD KEY `idx_chats_updated` (`updated_at`);

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
  ADD KEY `idx_files_user_ruta` (`user_id_`,`Ruta`(191)),
  ADD KEY `idx_files_user_access_found` (`user_id_`,`AccessType`,`Found`);

--
-- Indices de la tabla `FileS3_RepairControl`
--
ALTER TABLE `FileS3_RepairControl`
  ADD PRIMARY KEY (`id_`),
  ADD UNIQUE KEY `uq_prefix` (`Prefix`);

--
-- Indices de la tabla `FileS3_RepairLog`
--
ALTER TABLE `FileS3_RepairLog`
  ADD PRIMARY KEY (`id_`),
  ADD KEY `idx_prefix` (`Prefix`(191)),
  ADD KEY `idx_file` (`FileS3_id`);

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
-- Indices de la tabla `UserAIInteractions`
--
ALTER TABLE `UserAIInteractions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `Users`
--
ALTER TABLE `Users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `AccessControl`
--
ALTER TABLE `AccessControl`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `calls`
--
ALTER TABLE `calls`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ChatMessages`
--
ALTER TABLE `ChatMessages`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ChatSessions`
--
ALTER TABLE `ChatSessions`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `FileS3`
--
ALTER TABLE `FileS3`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `FileS3_RepairControl`
--
ALTER TABLE `FileS3_RepairControl`
  MODIFY `id_` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `FileS3_RepairLog`
--
ALTER TABLE `FileS3_RepairLog`
  MODIFY `id_` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `S3Folders`
--
ALTER TABLE `S3Folders`
  MODIFY `id_` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `UserAIInteractions`
--
ALTER TABLE `UserAIInteractions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `Users`
--
ALTER TABLE `Users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `AccessControl`
--
ALTER TABLE `AccessControl`
  ADD CONSTRAINT `AccessControl_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`);

--
-- Filtros para la tabla `calls`
--
ALTER TABLE `calls`
  ADD CONSTRAINT `fk_calls_user_caller` FOREIGN KEY (`from_user_id`) REFERENCES `Users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_calls_user_receiver` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `ChatMessages`
--
ALTER TABLE `ChatMessages`
  ADD CONSTRAINT `fk_msgs_session` FOREIGN KEY (`session_id_`) REFERENCES `ChatSessions` (`id_`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_msgs_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ChatSessions`
--
ALTER TABLE `ChatSessions`
  ADD CONSTRAINT `fk_chats_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `UserAIInteractions`
--
ALTER TABLE `UserAIInteractions`
  ADD CONSTRAINT `UserAIInteractions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
