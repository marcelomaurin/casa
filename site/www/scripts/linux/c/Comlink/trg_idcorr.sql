
CREATE TRIGGER trg_IDCORR BEFORE INSERT ON IDCORR
     FOR EACH ROW
     BEGIN
      INSERT INTO LOGIDCORR SET DtOcorrencia = now( ); IDCORR = NEW.ID; Corrente = NEW.Corrente; Consumo = NEW.Consumo;
     END;

