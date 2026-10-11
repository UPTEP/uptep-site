window.addEventListener('DOMContentLoaded',()=>{
 window.ui=SwaggerUIBundle({url:'/openapi.json',dom_id:'#swagger-ui',deepLinking:true,filter:true,docExpansion:'list',defaultModelsExpandDepth:0,displayRequestDuration:true,persistAuthorization:false,validatorUrl:null,supportedSubmitMethods:['get','post'],withCredentials:false,queryConfigEnabled:false});
});
