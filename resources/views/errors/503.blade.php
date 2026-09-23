@extends('errors::minimal')

{{-- Manutenção. Com `php artisan down --retry=N` a moldura acrescenta quanto esperar.
     Serve também ao `down --render=errors::503`, que desenha esta view antes, sem
     exceção nenhuma (ver o comentário do errors/minimal). --}}
@section('title', 'Em manutenção')
@section('code', '503')
@section('message', 'Voltamos em instantes')
@section('explicacao', 'O Stabil Money está passando por uma manutenção rápida. Seus dados continuam guardados.')
@section('acao', 'Tentar de novo')
