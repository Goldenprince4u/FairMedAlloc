@echo off
setlocal
cd /d "%~dp0"
set "PYTHON_BIN=C:\Users\quadr\AppData\Local\Programs\Python\Python311\python.exe"
"%PYTHON_BIN%" ml_service.py 127.0.0.1 5051
