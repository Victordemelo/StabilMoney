<?php

namespace App\Http\Requests;

/**
 * Mesmas regras da criação — a posse da categoria em si
 * é verificada pela CategoryPolicy no controller.
 */
class UpdateCategoryRequest extends StoreCategoryRequest
{
}
