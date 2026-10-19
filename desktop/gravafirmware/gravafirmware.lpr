program gravafirmware;

{$mode objfpc}{$H+}

uses
  {$IFDEF UNIX}
  cthreads,
  {$ENDIF}
  Interfaces, // LCL widgetset
  Forms, frmmain;

begin
  RequireDerivedFormResource := True;
  Application.Scaled := True;
  Application.Title := 'CASA - Gravador de Firmware';
  Application.Initialize;
  Application.CreateForm(TFormMain, FormMain);
  Application.Run;
end.
