@extends('layouts.install', ['no_header' => 1])
@section('title', 'Welcome - POS Installation')

@section('content')
<div class="container">
    
    <div class="row">
      <h3 class="text-center">{{ config('app.name', 'POS') }} Installation <small>Step 1 of 3</small></h3>

        <div class="col-md-8 col-md-offset-2">
          <hr/>
          @include('install.partials.nav', ['active' => 'install'])

          <div class="box box-primary active">
            <!-- /.box-header -->
            <div class="box-body">
              <h3 class="text-success">
                Welcome to POS Installation!
              </h3>

              <ol>


                <li>
                  <b>Database informations:</b>
                  <ul>
                    <li>Username</li>
                    <li>Password</li>
                    <li>Database Name</li>
                    <li>Database Host</li>
                  </ul>
                </li>
                <li>
                  <b>Mail Configuration</b> - SMTP details (optional)
                </li>
        
              </ol>

              
              <a href="{{route('install.details')}}" class="btn btn-primary pull-right">I Agree, Let's Go!</a>
            </div>
          <!-- /.box-body -->
          </div>

        </div>

    </div>
</div>
@endsection
