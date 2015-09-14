/**
 * Created by matias.miranda
 */

$(document).ready(function(){
    $("a.btnSignup").click(function(e){
        e.preventDefault();
        var product_id_tag = this.id;
        var product_id = product_id_tag.replace("idProduct-", "");
        $("#product-"+product_id+"-"+product_id).attr("checked", "checked");
        var plan_name = $("#box-"+product_id_tag).find(".boxHeading > h3").text();
        var price = "$" + $("#box-"+product_id_tag).find("span.price").text();
        $(".palnDetails span.plan_name").html(plan_name);
        $(".palnDetails span.plan-price").html(price);
        var list = "";
        $(".signUpBox").each(function(){
            list += $(this).find("ul.description").html();
            if(this.id == "box-" + product_id_tag){
                return false;
            }
        });
        $("ul.palnpoints").empty().append(list);
        $(".wrapper.plan-op").hide();
        $(".wrapper.plan-op-form").show();
    });
});