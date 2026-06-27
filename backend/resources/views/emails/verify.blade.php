<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { 
            display: inline-block; 
            padding: 12px 24px; 
            background-color: #4F46E5; 
            color: white; 
            text-decoration: none; 
            border-radius: 8px; 
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome to HubbyGlobal, {{ $user->name }}!</h1>
        <p>Please click the button below to verify your email address and start managing your stores.</p>
        <p>
            <a href="{{ $url }}" class="button">Verify Email Address</a>
        </p>
        <p>If you did not create an account, no further action is required.</p>
        <p>Regards,<br>HubbyGlobal Team</p>
    </div>
</body>
</html>
