<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

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

        $company = new Company();
        $company->name = $request->name;
        $company->phone = $request->phone;
        $company->address = $request->address;
        $company->email = $request->email;

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $fileName = uniqid('company_') . '.' . $image->getClientOriginalExtension();

            // Store image to cloud (Cloudflare R2 / AWS S3)
            $path = $image->storeAs('companies', $fileName, 's3'); // 's3' from config/filesystems.php
            $url = Storage::disk('s3')->url($path); // public URL to access it

            $company->image = $url; // Save full image URL in DB
        }

        $company->save();

        return response()->json([
            'message' => 'Company created successfully',
            'company' => $company,
        ], 201);
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