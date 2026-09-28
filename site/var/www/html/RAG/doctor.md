# Base RAG detalhada — doctor

## Identificacao
Repositorio: marcelomaurin/doctor
Documento construido a partir do README e da estrutura real do repositorio.
Use este material para responder perguntas tecnicas, funcionais e arquiteturais sobre o projeto.

## Conteudo tecnico do projeto

# Doctor — Multilingual README

---

# 🇧🇷 **PORTUGUÊS**

## 🧪 Doctor — Plataforma Open Source / Open Hardware para Automação Laboratorial

**Doctor** é uma plataforma **open source** e **open hardware** para automação laboratorial modular, integrando software, firmware e componentes mecânicos.  
O sistema usa aplicações em **Pascal/Lazarus** para **Raspberry Pi** e **Windows**, controlando funcionalidades como pesquisa de pacientes, tipos de exames, amostragem e execução de receitas automatizadas.

O módulo central **`banco.pas`** gerencia:
- Comunicação com **MySQL via ZeosLib**
- Troca de dados via TCP/IP e serial  
- Registros de operações e logs  
- Integridade dos dados com transações

O firmware (ex.: **`Transporte_firmware.ino`**) controla motores, servos, sensores, display Nextion e impressora térmica, interpretando comandos seriais com sincronização e gestão de estados.

**Pontos críticos:**
- Robustez na comunicação serial e TCP/IP  
- Prevenção de SQL Injection  
- Tratamento seguro dos estados no firmware  
- Configuração correta dos pacotes Lazarus  

A integração entre software, firmware e hardware garante uma solução laboratorial eficiente, expansível e confiável.

---

# 🇺🇸 **ENGLISH**

## 🧪 Doctor — Open Source / Open Hardware Platform for Laboratory Automation

**Doctor** is an **open source** and **open hardware** platform designed for modular laboratory automation, integrating software, firmware, and mechanical components.  
The system uses **Pascal/Lazarus** applications for **Raspberry Pi** and **Windows**, managing patient searching, exam type selection, sampling operations, and automated recipe execution.

The central module **`banco.pas`** handles:
- **MySQL communication via ZeosLib**
- TCP/IP and serial communication  
- Logging of operations and transactions  
- Data integrity through transactional control

The firmware (e.g., **`Transporte_firmware.ino`**) controls motors, servos, sensors, the Nextion display, and the thermal printer, executing serial commands with state management and synchronization.

**Critical points:**
- Robust serial and TCP/IP communication  
- SQL Injection prevention  
- Accurate firmware state handling  
- Proper Lazarus package configuration  

The integration of software, firmware, and hardware ensures an efficient, scalable, and reliable laboratory automation solution.

---

# 🇪🇸 **ESPAÑOL**

## 🧪 Doctor — Plataforma Open Source / Open Hardware para Automatización de Laboratorios

**Doctor** es una plataforma **open source** y **open hardware** para la automatización modular de laboratorios, integrando software, firmware y componentes mecánicos.  
El sistema utiliza aplicaciones en **Pascal/Lazarus** para **Raspberry Pi** y **Windows**, gestionando la búsqueda de pacientes, tipos de exámenes, operaciones de muestreo y ejecución automática de rutinas.

El módulo central **`banco.pas`** gestiona:
- Comunicación con **MySQL mediante ZeosLib**
- Intercambio de datos vía TCP/IP y puerto serial  
- Registros de operaciones y transacciones  
- Integridad de datos con control transaccional

El firmware (p. ej., **`Transporte_firmware.ino`**) controla motores, servos, sensores, pantalla Nextion e impresora térmica, procesando comandos seriales con gestión de estados sincronizada.

**Puntos críticos:**
- Robustez en comunicaciones seriales y TCP/IP  
- Prevención de inyección SQL  
- Manejo seguro del estado del firmware  
- Configuración correcta de paquetes Lazarus  

La integración entre software, firmware y hardware garantiza una solución de laboratorio eficiente, escalable y confiable.

---

# 🇸🇦 **العربية**

## 🧪 Doctor — منصة مفتوحة المصدر للأتمتة المخبرية (Open Source / Open Hardware)

**Doctor** هي منصة **مفتوحة المصدر** و **مفتوحة العتاد** مخصّصة للأتمتة المخبرية بطريقة معيارية، تجمع بين البرمجيات والفيرموير والمكوّنات الميكانيكية.  
يستخدم النظام تطبيقات **Pascal/Lazarus** لأنظمة **Raspberry Pi** و **Windows** للتحكم في البحث عن المرضى، وأنواع الفحوصات، وعمليات أخذ العينات، وتنفيذ الوصفات المخبرية تلقائيًا.

الوحدة المركزية **`banco.pas`** تدير:
- الاتصال بقاعدة بيانات **MySQL عبر ZeosLib**
- تبادل البيانات عبر TCP/IP والمنفذ التسلسلي  
- تسجيل العمليات والمعاملات  
- حماية سلامة البيانات عبر المعاملات

الفيرموير (مثل **`Transporte_firmware.ino`**) يتحكم بالمحركات، والسيرفو، والمستشعرات، وشاشة Nextion والطابعة الحرارية، وينفذ الأوامر عبر المنفذ التسلسلي مع إدارة دقيقة للحالة.

**نقاط حرجة:**
- الاتصالات التسلسلية وTCP/IP بشكل آمن ومتقدم  
- الحماية من حقن SQL  
- إدارة صحيحة لحالات الفيرموير  
- إعداد صحيح لحزم Lazarus  

دمج البرمجيات والفيرموير والعتاد يوفر نظامًا مخبريًا فعالًا، قابلًا للتوسّع وموثوقًا.

---

# 🇨🇳 **中文 (Chinês Simplificado)**

## 🧪 Doctor — 实验室自动化开源 / 开放硬件平台

**Doctor** 是一个用于模块化实验室自动化的 **开源** 和 **开放硬件** 平台，集成了软件、固件和机械组件。  
系统使用 **Pascal/Lazarus** 开发，可运行在 **Raspberry Pi** 和 **Windows** 上，实现病人查询、检查类型选择、采样流程以及自动化操作执行。

核心模块 **`banco.pas`** 负责：
- 使用 ZeosLib 与 **MySQL** 通信
- TCP/IP 与串口数据交换  
- 操作与事务日志记录  
- 通过事务管理确保数据完整性

固件（如 **`Transporte_firmware.ino`**）控制电机、伺服器、环境传感器、Nextion 显示屏和热敏打印机，并通过串口解析指令，精准同步设备状态。

**关键点：**
- 串口与 TCP/IP 通信的鲁棒性  
- 防止 SQL 注入  
- 正确处理固件状态  
- Lazarus 依赖包配置正确  

软件、固件与硬件层的深度整合，使该平台成为高效、可扩展、可靠的实验室自动化解决方案。



## Orientacao para o JARVIS
Ao responder sobre este projeto, procure identificar no texto acima: objetivo, linguagens e plataformas, modulos, funcionalidades, dependencias, hardware, comunicacao, bancos de dados, fluxo de funcionamento, instalacao, limitacoes e estado dos recursos. Nao presuma funcionalidades que nao estejam documentadas. Quando a pergunta for sobre implementacao, explique os componentes e a relacao entre eles.
