@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="card-body">
            <h4 class="mb-3">Submit actor info</h4>

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('actors.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input name="email" value="{{ old('email') }}" class="form-control" />
                </div>

                <div class="mb-3">
                    <label class="form-label">Actor description</label>
                    <textarea name="description" class="form-control" rows="5">{{ old('description') }}</textarea>
                    <div class="form-text">Please enter your first name and last name, and also provide your address.</div>
                </div>

                <button class="btn btn-primary">Submit</button>
            </form>
        </div>
    </div>
@endsection