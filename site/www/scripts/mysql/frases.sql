#Estrutura da tabela `frases`

use jornadadb;

CREATE TABLE IF NOT EXISTS `frases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `texto` varchar(250) COLLATE utf8_unicode_ci NOT NULL,
  `autor` varchar(30) COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=13;
