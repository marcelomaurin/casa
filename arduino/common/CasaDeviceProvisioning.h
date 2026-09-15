/*
 * CASA / JARVIS - Protocolo de Pareamento e Passagem de Chaves via Celular
 * Biblioteca comum C++ para microcontroladores ESP32 e ESP8266.
 *
 * Filosofia:
 *   Nenhum token ou chave secreta deve ser hardcoded no firmware.
 *   Dispositivos novos geram um codigo efemero de 6 digitos e solicitam
 *   autorizacao a API. O administrador visualiza e aprova no JARVIS Mobile
 *   no celular, que libera a chave individual criptografica do device.
 */

#ifndef CASA_DEVICE_PROVISIONING_H
#define CASA_DEVICE_PROVISIONING_H

#include <Arduino.h>

#if defined(ESP32)
  #include <WiFi.h>
  #include <HTTPClient.h>
  #include <Preferences.h>
#elif defined(ESP8266)
  #include <ESP8266WiFi.h>
  #include <ESP8266HTTPClient.h>
  #include <WiFiClientSecureBearSSL.h>
  #include <EEPROM.h>
#endif

enum CasaProvisionState {
  CASA_STATE_UNPROVISIONED = 0,
  CASA_STATE_PAIRING_REQUESTED,
  CASA_STATE_AUTHORIZED,
  CASA_STATE_REJECTED,
  CASA_STATE_ERROR
};

class CasaDeviceProvisioning {
private:
  String _baseUrl;
  String _deviceId;
  String _deviceToken;
  String _requestId;
  String _pairingCode;
  CasaProvisionState _state;

#if defined(ESP32)
  Preferences _prefs;
#endif

  String getMacAddress() {
    uint8_t mac[6];
    WiFi.macAddress(mac);
    char buf[18];
    snprintf(buf, sizeof(buf), "%02X:%02X:%02X:%02X:%02X:%02X",
             mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
    return String(buf);
  }

  String generatePairingCode() {
    randomSeed(micros() + ESP.getFreeHeap());
    long code = random(100000, 999999);
    return String(code);
  }

public:
  CasaDeviceProvisioning() {
    _baseUrl = "https://maurinsoft.com.br/casa";
    _state = CASA_STATE_UNPROVISIONED;
  }

  bool isProvisioned() {
    return (_deviceToken.length() >= 20 && _deviceId.length() > 0);
  }

  String getDeviceToken() const { return _deviceToken; }
  String getDeviceId() const { return _deviceId; }
  String getBaseUrl() const { return _baseUrl; }
  String getPairingCode() const { return _pairingCode; }
  String getRequestId() const { return _requestId; }
  CasaProvisionState getState() const { return _state; }

  void begin(const String& defaultBaseUrl = "https://maurinsoft.com.br/casa") {
    _baseUrl = defaultBaseUrl;
    loadCredentials();
  }

  void loadCredentials() {
#if defined(ESP32)
    _prefs.begin("casa-auth", true);
    _deviceToken = _prefs.getString("token", "");
    _deviceId = _prefs.getString("dev_id", "");
    String storedUrl = _prefs.getString("base_url", "");
    if (storedUrl.length() > 0) _baseUrl = storedUrl;
    _prefs.end();
#elif defined(ESP8266)
    EEPROM.begin(512);
    uint16_t magic = (EEPROM.read(0) << 8) | EEPROM.read(1);
    if (magic == 0xCA5A) {
      char bufTok[128];
      char bufId[64];
      int idx = 2;
      for (int i = 0; i < 127; i++) { bufTok[i] = (char)EEPROM.read(idx++); if (bufTok[i] == 0) break; }
      bufTok[127] = 0;
      for (int i = 0; i < 63; i++) { bufId[i] = (char)EEPROM.read(idx++); if (bufId[i] == 0) break; }
      bufId[63] = 0;
      _deviceToken = String(bufTok);
      _deviceId = String(bufId);
    }
    EEPROM.end();
#endif
    if (isProvisioned()) {
      _state = CASA_STATE_AUTHORIZED;
    } else {
      _state = CASA_STATE_UNPROVISIONED;
    }
  }

  void saveCredentials(const String& deviceId, const String& token, const String& baseUrl) {
    _deviceId = deviceId;
    _deviceToken = token;
    if (baseUrl.length() > 0) _baseUrl = baseUrl;

#if defined(ESP32)
    _prefs.begin("casa-auth", false);
    _prefs.putString("token", _deviceToken);
    _prefs.putString("dev_id", _deviceId);
    _prefs.putString("base_url", _baseUrl);
    _prefs.end();
#elif defined(ESP8266)
    EEPROM.begin(512);
    EEPROM.write(0, 0xCA);
    EEPROM.write(1, 0x5A);
    int idx = 2;
    for (size_t i = 0; i < _deviceToken.length() && i < 127; i++) {
      EEPROM.write(idx++, _deviceToken[i]);
    }
    EEPROM.write(idx++, 0);
    for (size_t i = 0; i < _deviceId.length() && i < 63; i++) {
      EEPROM.write(idx++, _deviceId[i]);
    }
    EEPROM.write(idx++, 0);
    EEPROM.commit();
    EEPROM.end();
#endif
    _state = CASA_STATE_AUTHORIZED;
  }

  void resetCredentials() {
    _deviceId = "";
    _deviceToken = "";
#if defined(ESP32)
    _prefs.begin("casa-auth", false);
    _prefs.clear();
    _prefs.end();
#elif defined(ESP8266)
    EEPROM.begin(512);
    EEPROM.write(0, 0);
    EEPROM.write(1, 0);
    EEPROM.commit();
    EEPROM.end();
#endif
    _state = CASA_STATE_UNPROVISIONED;
  }

  bool requestPairing(const String& deviceType, const String& modelName, const String& capabilitiesJson = "[]") {
    if (WiFi.status() != WL_CONNECTED) return false;

    _pairingCode = generatePairingCode();
    String mac = getMacAddress();

    String url = _baseUrl + "/api/v1/provision.php?acao=solicitar_pareamento";

    String payload = "{";
    payload += "\"mac\":\"" + mac + "\",";
    payload += "\"type\":\"" + deviceType + "\",";
    payload += "\"model\":\"" + modelName + "\",";
    payload += "\"firmware_version\":\"1.0.0\",";
    payload += "\"pairing_code\":\"" + _pairingCode + "\",";
    payload += "\"capabilities\":" + capabilitiesJson;
    payload += "}";

    HTTPClient http;
#if defined(ESP8266)
    std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
    client->setInsecure();
    if (!http.begin(*client, url)) return false;
#elif defined(ESP32)
    WiFiClientSecure client;
    client.setInsecure();
    if (!http.begin(client, url)) return false;
#endif

    http.addHeader("Content-Type", "application/json");
    http.setTimeout(10000);

    int httpCode = http.POST(payload);
    if (httpCode == 200) {
      String resp = http.getString();
      int ridPos = resp.indexOf("\"request_id\":\"");
      if (ridPos != -1) {
        int start = ridPos + 14;
        int end = resp.indexOf("\"", start);
        _requestId = resp.substring(start, end);
        _state = CASA_STATE_PAIRING_REQUESTED;
        http.end();
        return true;
      }
    }

    http.end();
    return false;
  }

  bool pollPairingStatus() {
    if (_state != CASA_STATE_PAIRING_REQUESTED || _requestId.length() == 0) return false;
    if (WiFi.status() != WL_CONNECTED) return false;

    String url = _baseUrl + "/api/v1/provision.php?acao=consultar_pareamento";

    String payload = "{";
    payload += "\"request_id\":\"" + _requestId + "\",";
    payload += "\"mac\":\"" + getMacAddress() + "\",";
    payload += "\"pairing_code\":\"" + _pairingCode + "\"";
    payload += "}";

    HTTPClient http;
#if defined(ESP8266)
    std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
    client->setInsecure();
    if (!http.begin(*client, url)) return false;
#elif defined(ESP32)
    WiFiClientSecure client;
    client.setInsecure();
    if (!http.begin(client, url)) return false;
#endif

    http.addHeader("Content-Type", "application/json");
    http.setTimeout(10000);

    int httpCode = http.POST(payload);
    if (httpCode == 200) {
      String resp = http.getString();
      if (resp.indexOf("\"status_pareamento\":\"authorized\"") != -1) {
        String devId = "";
        String tok = "";
        int idPos = resp.indexOf("\"device_id\":\"");
        if (idPos != -1) {
          int s = idPos + 13;
          devId = resp.substring(s, resp.indexOf("\"", s));
        }
        int tokPos = resp.indexOf("\"device_token\":\"");
        if (tokPos != -1) {
          int s = tokPos + 16;
          tok = resp.substring(s, resp.indexOf("\"", s));
        }
        if (devId.length() > 0 && tok.length() > 0) {
          saveCredentials(devId, tok, _baseUrl);
          http.end();
          return true;
        }
      } else if (resp.indexOf("\"status\":\"rejected\"") != -1) {
        _state = CASA_STATE_REJECTED;
      }
    }

    http.end();
    return false;
  }
};

#endif // CASA_DEVICE_PROVISIONING_H
