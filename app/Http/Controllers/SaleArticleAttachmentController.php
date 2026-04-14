<?php

namespace App\Http\Controllers;

use App\Models\SaleArticleAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SaleArticleAttachmentController extends Controller
{
    function store(Request $request)
    {
        $request->validate([
            'sale_id'    => 'required|integer',
            'article_id' => 'required|integer',
            'file'       => 'required|file|max:20480',
        ]);

        $file = $request->file('file');
        $original_name = $file->getClientOriginalName();
        $dir = 'vender-items/' . $request->sale_id;
        $file_path = $file->store($dir);

        $attachment = SaleArticleAttachment::create([
            'sale_id'       => $request->sale_id,
            'article_id'    => $request->article_id,
            'file_path'     => $file_path,
            'original_name' => $original_name,
            'observation'   => $request->observation,
        ]);

        return response()->json(['model' => $attachment], 201);
    }

    function by_sale($sale_id)
    {
        $models = SaleArticleAttachment::where('sale_id', $sale_id)
                    ->orderBy('created_at', 'ASC')
                    ->get();

        return response()->json(['models' => $models], 200);
    }

    function show_file(Request $request, $id)
    {
        $attachment = SaleArticleAttachment::findOrFail($id);

        if (!Storage::exists($attachment->file_path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        $file_content = Storage::get($attachment->file_path);
        $mime = Storage::mimeType($attachment->file_path);
        $disposition = $request->get('download') ? 'attachment' : 'inline';

        return response()->make($file_content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => $disposition . '; filename="' . $attachment->original_name . '"',
        ]);
    }

    function destroy($id)
    {
        $attachment = SaleArticleAttachment::findOrFail($id);

        if (Storage::exists($attachment->file_path)) {
            Storage::delete($attachment->file_path);
        }

        $attachment->delete();

        return response()->json(['message' => 'Eliminado'], 200);
    }
}
