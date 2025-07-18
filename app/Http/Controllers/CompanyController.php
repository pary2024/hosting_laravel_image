<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{
    // GET all companies with full image URL
    public function index()
    {
        $companies = Company::all()->map(function ($company) {
            $company->image = $company->image
                ? $company->image // already full URL
                : null;
            return $company;
        });

        return response()->json([
            'companies' => $companies
        ], 200);
    }

    // POST create new company with image to cloud
    public function store(Request $request)
{
    try {
        $validate = Validator::make($request->all(), [
            'name' => 'required',
            'phone' => 'required',
            'address' => 'required',
            'email' => 'required|email',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validate->errors(),
            ], 422);
        }

        $company = new \App\Models\Company();
        $company->name = $request->name;
        $company->phone = $request->phone;
        $company->address = $request->address;
        $company->email = $request->email;

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $fileName = uniqid('company_') . '.' . $image->getClientOriginalExtension();

            // Store to S3
            $path = $image->storeAs('companies', $fileName, 's3');
            $url = Storage::disk('s3')->url($path);

            $company->image = $url;
        }

        $company->save();

        return response()->json([
            'message' => 'Company created successfully',
            'company' => $company,
        ], 201);
        
    } catch (\Throwable $e) {
        // Log the real error for developer
        Log::error('Company store error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        // Show error in Postman (don't show full trace)
        return response()->json([
            'message' => 'Server error',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    // DELETE a company and remove image from cloud if exists
    public function delete($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        // Delete image from cloud if exists
        if ($company->image) {
            // Extract path from URL
            $path = parse_url($company->image, PHP_URL_PATH); // /companies/filename.jpg
            $path = ltrim($path, '/'); // remove starting slash
            Storage::disk('s3')->delete($path); // delete from cloud
        }

        // Delete related users if relationship exists
        if (method_exists($company, 'users')) {
            $company->users()->delete();
        }

        // Delete the company
        $company->delete();

        return response()->json(['message' => 'Company and its users deleted successfully'], 200);
    }
}
